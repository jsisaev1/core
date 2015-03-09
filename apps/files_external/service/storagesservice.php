<?php
/**
 * Copyright (c) 2015 Vincent Petry <pvince81@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Files_external\Service;

use \OCA\Files_external\NotFoundException;
use \OCP\IUserSession;

/**
 * Service class to manage external storages
 */
class StoragesService {
	/**
	 * @var IUserSession
	 */
	private $userSession;

	public function __construct(
		IUserSession $userSession
	) {
		$this->userSession = $userSession;
	}


	/**
	 * Read the external storages config
	 *
	 * @param string $user user name or null for global config
	 *
	 * @return array map of storage id to storage config
	 */
	public function readConfig($user = null) {
		$mountPoints = \OC_Mount_Config::readData($user);

		/**
		 * Here is the how the horribly messy mount point array looks like
		 * from the mount.json file:
		 *
		 * $storageOptions = $mountPoints[$mountType][$applicable][$mountPath]
		 *
		 * - $mountType is either "user" or "group"
		 * - $applicable is the name of a user or group (or the current user for personal mounts)
		 * - $mountPath is the mount point path (where the storage must be mounted)
		 * - $storageOptions is a map of storage options:
		 *     - "priority": storage priority
		 *     - "backend": backend class name
		 *     - "options": backend-specific options
		 *     - "personal": true for personal storages
		 */

		// group by storage id
		$storages = [];
		foreach ($mountPoints as $mountType => $applicables) {
			foreach ($applicables as $applicable => $mountPaths) {
				foreach ($mountPaths as $rootMountPath => $storageOptions) {
					// the root mount point is in the format "/$user/files/the/mount/point"
					// we remove the "/$user/files" prefix
					$parts = explode('/', trim($rootMountPath, '/'), 3);
					if (count($parts) < 3) {
						// something went wrong, skip
						\OCP\Util::writeLog(
							'files_external',
							'Could not parse mount point "' . $rootMountPath . '"',
							\OCP\Util::ERROR
						);
						continue;
					}

					$relativeMountPath = $parts[2];

					$configId = (int)$storageOptions['id'];
					if (isset($storages[$configId])) {
						$currentStorage = $storages[$configId];
					} else {
						$currentStorage = [];
						$currentStorage['mountPoint'] = $relativeMountPath;
					}

					$currentStorage['id'] = $configId;
					// note: the storage ID is NOT the config ID, it's the id
					// used in oc_storages. There's a 1-N relationship because
					// of the "$user" variable that can be used in config options
					$currentStorage['storage_id'] = (int)$storageOptions['storage_id'];
					$currentStorage['backendClass'] = $storageOptions['class'];
					$currentStorage['backendOptions'] = $storageOptions['options'];
					$currentStorage['priority'] = $storageOptions['priority'];

					if (!isset($currentStorage['applicableUsers'])) {
						$currentStorage['applicableUsers'] = [];
					}

					if (!isset($currentStorage['applicableGroups'])) {
						$currentStorage['applicableGroups'] = [];
					}

					if ($mountType === \OC_Mount_Config::MOUNT_TYPE_USER) {
						if ($applicable !== 'all') {
							$currentStorage['applicableUsers'][] = $applicable;
						}
					} else if ($mountType === \OC_Mount_Config::MOUNT_TYPE_GROUP) {
						$currentStorage['applicableGroups'][] = $applicable;
					}
					$storages[$configId] = $currentStorage;
				}
			}
		}

		// decrypt passwords
		foreach ($storages as &$storage) {
			$storage['backendOptions'] = \OC_Mount_Config::decryptPasswords($storage['backendOptions']);
		}

		return $storages;
	}

	/**
	 * Add mount point into the messy mount point structure
	 *
	 * @param array $mountPoints messy array of mount points
	 * @param string $mountType mount type
	 * @param string $applicable single applicable user or group
	 * @param string $rootMountPoint root mount point to use
	 * @param array $storageConfig storage config to set to the mount point
	 */
	private function addMountPoint(&$mountPoints, $mountType, $applicable, $rootMountPoint, $storageConfig) {
		if (!isset($mountPoints[$mountType])) {
			$mountPoints[$mountType] = [];
		}

		if (!isset($mountPoints[$mountType][$applicable])) {
			$mountPoints[$mountType][$applicable] = [];
		}

		$mountPoints[$mountType][$applicable][$rootMountPoint] = [
			'id' => $storageConfig['id'],
			'class' => $storageConfig['backendClass'],
			'options' => $storageConfig['backendOptions'],
			'priority' => $storageConfig['priority'],
		];
	}

	/**
	 * Write the storages to the configuration.
	 *
	 * @param string $user user or null for global config
	 * @param array $storages map of storage id to storage config
	 */
	public function writeConfig($user = null, $storages) {
		$mountTypesMap = [
			'applicableUsers' => \OC_Mount_Config::MOUNT_TYPE_USER,
			'applicableGroups' => \OC_Mount_Config::MOUNT_TYPE_GROUP,
		];

		// let the horror begin
		$mountPoints = [];
		foreach ($storages as $storageConfig) {
			$mountPoint = $storageConfig['mountPoint'];
			$storageConfig['backendOptions'] = \OC_Mount_Config::encryptPasswords($storageConfig['backendOptions']);

			if (!empty($user)) {
				// personal mount
				$rootMountPoint = '/' . $user . '/files/' . ltrim($mountPoint, '/');
			} else {
				// system mount
				$rootMountPoint = '/$user/files/' . ltrim($mountPoint, '/');
			}

			$applicableAdded = false;
			foreach ($mountTypesMap as $fieldName => $mountType) {
				foreach ($storageConfig[$fieldName] as $applicable) {
					$this->addMountPoint(
						$mountPoints,
						$mountType,
						$applicable,
						$rootMountPoint,
						$storageConfig
					);
					$applicableAdded = true;
				}
			}

			// if neither "applicableGroups" or "applicableUsers" were set, use "all" user
			if (!$applicableAdded) {
				$this->addMountPoint(
					$mountPoints,
					\OC_Mount_Config::MOUNT_TYPE_USER,
					'all',
					$rootMountPoint,
					$storageConfig
				);
			}
		}

		\OC_Mount_Config::writeData($user, $mountPoints);
	}

	/**
	 * Get a storage with status
	 *
	 * @param int $id
	 * @param bool $isPersonal
	 *
	 * @return array
	 */
	public function getStorage($id, $isPersonal) {
		$user = null;
		if ($isPersonal) {
			$user = $this->userSession->getUser()->getUID();
		}

		$allStorages = $this->readConfig($user);

		if (!isset($allStorages[$id])) {
			throw new NotFoundException('Storage with id "' . $id . '" not found');
		}

		$storage = $allStorages[$id];
		$storage['status'] = \OC_Mount_Config::getBackendStatus(
			$storage['backendClass'],
			$storage['backendOptions'],
			$isPersonal
		);

		return $storage;
	}

	/**
	 * Add new storage to the configuration
	 *
	 * @param array $newStorage storage attributes
	 * @param bool $isPersonal true for personal storage, false otherwise
	 *
	 * @return array storage attributes, with added id
	 */
	public function addStorage($newStorage, $isPersonal) {
		$user = null;
		if ($isPersonal) {
			$user = $this->userSession->getUser()->getUID();
		}

		$allStorages = $this->readConfig($user);

		// TODO: IMPORTANT: auto-create the oc_storages entry so
		// we get a numeric_id
		$configId = $this->generateNextId($allStorages);
		$newStorage['id'] = $configId;

		// add new storage
		$allStorages[$configId] = $newStorage;

		$this->writeConfig($user, $allStorages);

		// sort out hooks/events
		/*
		\OC_Hook::emit(
			\OC\Files\Filesystem::CLASSNAME,
			\OC\Files\Filesystem::signal_create_mount,
			array(
				\OC\Files\Filesystem::signal_param_path => $relMountPoint,
				\OC\Files\Filesystem::signal_param_mount_type => $mountType,
				\OC\Files\Filesystem::signal_param_users => $applicable,
			)
		);
		 */

		$newStorage['status'] = \OC_Mount_Config::STATUS_SUCCESS;
		return $newStorage;
	}

	/**
	 * Update storage to the configuration
	 *
	 * @param array $updatedStorage storage attributes
	 * @param bool $isPersonal true for personal storage, false otherwise
	 *
	 * @return array storage attributes
	 * @throws NotFoundException
	 */
	public function updateStorage($updatedStorage, $isPersonal) {
		$user = null;
		if ($isPersonal) {
			$user = $this->userSession->getUser()->getUID();
		}

		$allStorages = $this->readConfig($user);

		$id = $updatedStorage['id'];
		if (!isset($allStorages[$id])) {
			throw new NotFoundException('Storage with id "' . $id . '" not found');
		}

		$storage = $allStorages[$id];
		$storage = array_merge($updatedStorage);
		$allStorages[$id] = $storage;

		$this->writeConfig($user, $allStorages);

		return $this->getStorage($id, $isPersonal);
	}

	/**
	 * Delete the storage with the given id.
	 *
	 * @param int $id storage id
	 * @param bool $isPersonal true for personal storage, false otherwise
	 *
	 * @throws NotFoundException
	 */
	public function removeStorage($id, $isPersonal) {
		$user = null;
		if ($isPersonal) {
			$user = $this->userSession->getUser()->getUID();
		}

		$allStorages = $this->readConfig($user);

		if (!isset($allStorages[$id])) {
			throw new NotFoundException('Storage with id "' . $id . '" not found');
		}

		unset($allStorages[$id]);

		$this->writeConfig($user, $allStorages);

		// TODO: sort out hooks/events
		/**
		\OC_Hook::emit(
			\OC\Files\Filesystem::CLASSNAME,
			\OC\Files\Filesystem::signal_delete_mount,
			array(
				\OC\Files\Filesystem::signal_param_path => $relMountPoints,
				\OC\Files\Filesystem::signal_param_mount_type => $mountType,
				\OC\Files\Filesystem::signal_param_users => $applicable,
			)
		);
		**/
	}

	/**
	 * Generates a configuration id to use for a new configuration entry.
	 *
	 * @param array $allStorages array of all storage configs
	 *
	 * @return int id
	 */
	private function generateNextId($allStorages) {
		if (empty($allStorages)) {
			return 1;
		}
		// note: this will mess up with with concurrency,
		// but so did the mount.json. This horribly hack
		// will disappear once we move to DB tables to
		// store the config
		return max(array_keys($allStorages)) + 1;
	}

}
