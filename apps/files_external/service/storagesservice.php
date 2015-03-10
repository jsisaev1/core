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
use \OC\Files\Filesystem;

/**
 * Service class to manage external storages
 */
abstract class StoragesService {

	/**
	 * Read legacy config data
	 *
	 * @return array list of mount configs
	 */
	protected function readLegacyConfig() {
		// read global config
		return \OC_Mount_Config::readData();
	}

	/**
	 * Read the external storages config
	 *
	 * @return array map of storage id to storage config
	 */
	protected function readConfig() {
		$mountPoints = $this->readLegacyConfig();

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
					if (isset($storageOptions['storage_id'])) {
						$currentStorage['storage_id'] = (int)$storageOptions['storage_id'];
					}
					$currentStorage['backendClass'] = $storageOptions['class'];
					$currentStorage['backendOptions'] = $storageOptions['options'];
					if (isset($storageOptions['priority'])) {
						$currentStorage['priority'] = $storageOptions['priority'];
					}

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
	protected function addMountPoint(&$mountPoints, $mountType, $applicable, $rootMountPoint, $storageConfig) {
		if (!isset($mountPoints[$mountType])) {
			$mountPoints[$mountType] = [];
		}

		if (!isset($mountPoints[$mountType][$applicable])) {
			$mountPoints[$mountType][$applicable] = [];
		}

		$options = [
			'id' => $storageConfig['id'],
			'class' => $storageConfig['backendClass'],
			'options' => $storageConfig['backendOptions'],
		];

		if (isset($storageConfig['priority'])) {
			$options['priority'] = $storageConfig['priority'];
		}

		$mountPoints[$mountType][$applicable][$rootMountPoint] = $options;
	}

	/**
	 * Write the storages to the configuration.
	 *
	 * @param array $storages map of storage id to storage config
	 */
	protected function writeConfig($storages) {
		// abstract
	}

	/**
	 * Get a storage with status
	 *
	 * @param int $id
	 *
	 * @return array
	 */
	public function getStorage($id) {
		$allStorages = $this->readConfig();

		if (!isset($allStorages[$id])) {
			throw new NotFoundException('Storage with id "' . $id . '" not found');
		}

		return $allStorages[$id];
	}

	/**
	 * Add new storage to the configuration
	 *
	 * @param array $newStorage storage attributes
	 *
	 * @return array storage attributes, with added id
	 */
	public function addStorage($newStorage) {
		$allStorages = $this->readConfig();

		$configId = $this->generateNextId($allStorages);
		$newStorage['id'] = $configId;

		// add new storage
		$allStorages[$configId] = $newStorage;

		$this->writeConfig($allStorages);

		$this->triggerHooks($newStorage, Filesystem::signal_create_mount);

		$newStorage['status'] = \OC_Mount_Config::STATUS_SUCCESS;
		return $newStorage;
	}

	/**
	 * Triggers the given hook signal for all the applicables given
	 *
	 * @param string $signal signal
	 * @param string $mountPoint hook mount pount param
	 * @param string $mountType hook mount type param
	 * @param array $applicableArray array of applicable users/groups for which to trigger the hook
	 */
	protected function triggerApplicableHooks($signal, $mountPoint, $mountType, $applicableArray) {
		foreach ($applicableArray as $applicable) {
			\OC_Hook::emit(
				Filesystem::CLASSNAME,
				$signal,
				[
					Filesystem::signal_param_path => $mountPoint,
					Filesystem::signal_param_mount_type => $mountType,
					Filesystem::signal_param_users => $applicable,
				]
			);
		}
	}

	/**
	 * Triggers $signal for all applicable users of the given
	 * storage
	 *
	 * @param array $storage storage data
	 * @param string $signal signal to trigger
	 */
	protected function triggerHooks($storage, $signal) {
		// to be implemented by subclass
	}

	/**
	 * Triggers signal_create_mount or signal_delete_mount to
	 * accomodate for additions/deletions in applicableUsers
	 * and applicableGroups fields.
	 *
	 * @param array $oldStorage old storage data
	 * @param array $newStorage new storage data
	 */
	protected function triggerChangeHooks($oldStorage, $newStorage) {
		// to be implemented by subclass
	}

	/**
	 * Update storage to the configuration
	 *
	 * @param array $updatedStorage storage attributes
	 *
	 * @return array storage attributes
	 * @throws NotFoundException
	 */
	public function updateStorage($updatedStorage) {
		$allStorages = $this->readConfig();

		$id = $updatedStorage['id'];
		if (!isset($allStorages[$id])) {
			throw new NotFoundException('Storage with id "' . $id . '" not found');
		}

		$oldStorage = $allStorages[$id];
		$allStorages[$id] = $updatedStorage;

		$this->writeConfig($allStorages);

		$this->triggerChangeHooks($oldStorage, $updatedStorage);

		return $this->getStorage($id);
	}

	/**
	 * Delete the storage with the given id.
	 *
	 * @param int $id storage id
	 *
	 * @throws NotFoundException
	 */
	public function removeStorage($id) {
		$allStorages = $this->readConfig();

		if (!isset($allStorages[$id])) {
			throw new NotFoundException('Storage with id "' . $id . '" not found');
		}

		$deletedStorage = $allStorages[$id];
		unset($allStorages[$id]);

		$this->writeConfig($allStorages);

		$this->triggerHooks($deletedStorage, Filesystem::signal_delete_mount);
	}

	/**
	 * Generates a configuration id to use for a new configuration entry.
	 *
	 * @param array $allStorages array of all storage configs
	 *
	 * @return int id
	 */
	protected function generateNextId($allStorages) {
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
