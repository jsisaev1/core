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
 * Service class to manage global external storages
 */
class GlobalStoragesService extends StoragesService {
	/**
	 * Get a storage with status
	 *
	 * @param int $id
	 *
	 * @return array
	 */
	public function getStorage($id) {
		$storage = parent::getStorage($id);

		$storage['status'] = \OC_Mount_Config::getBackendStatus(
			$storage['backendClass'],
			$storage['backendOptions'],
			false
		);

		return $storage;
	}

	/**
	 * Write the storages to the configuration.
	 *
	 * @param string $user user or null for global config
	 * @param array $storages map of storage id to storage config
	 */
	public function writeConfig($storages) {
		$mountTypesMap = [
			'applicableUsers' => \OC_Mount_Config::MOUNT_TYPE_USER,
			'applicableGroups' => \OC_Mount_Config::MOUNT_TYPE_GROUP,
		];

		// let the horror begin
		$mountPoints = [];
		foreach ($storages as $storageConfig) {
			$mountPoint = $storageConfig['mountPoint'];
			$storageConfig['backendOptions'] = \OC_Mount_Config::encryptPasswords($storageConfig['backendOptions']);

			// system mount
			$rootMountPoint = '/$user/files/' . ltrim($mountPoint, '/');

			$applicableAdded = false;
			foreach ($mountTypesMap as $fieldName => $mountType) {
				if (!isset($storageConfig[$fieldName])) {
					continue;
				}
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

		\OC_Mount_Config::writeData(null, $mountPoints);
	}

	/**
	 * Triggers $signal for all applicable users of the given
	 * storage
	 *
	 * @param array $storage storage data
	 * @param string $signal signal to trigger
	 */
	protected function triggerHooks($storage, $signal) {
		if (empty($storage['applicableUsers']) && empty($storage['applicableGroups'])) {
			// raise for user "all"
			$this->triggerApplicableHooks(
				$signal,
				$storage['mountPoint'],
				\OC_Mount_Config::MOUNT_TYPE_USER,
				['all']
			);
			return;
		}

		if (isset($storage['applicableUsers'])) {
			$this->triggerApplicableHooks(
				$signal,
				$storage['mountPoint'],
				\OC_Mount_Config::MOUNT_TYPE_USER,
				$storage['applicableUsers']
			);
		}

		if (isset($storage['applicableGroups'])) {
			$this->triggerApplicableHooks(
				$signal,
				$storage['mountPoint'],
				\OC_Mount_Config::MOUNT_TYPE_GROUP,
				$storage['applicableGroups']
			);
		}
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
		// if mount point changed, it's like a deletion + creation
		if ($oldStorage['mountPoint'] !== $newStorage['mountPoint']) {
			$this->triggerHooks($oldStorage, Filesystem::signal_delete_mount);
			$this->triggerHooks($newStorage, Filesystem::signal_create_mount);
			return;
		}

		$userAdditions = array_diff($newStorage['applicableUsers'], $oldStorage['applicableUsers']);
		$userDeletions = array_diff($oldStorage['applicableUsers'], $newStorage['applicableUsers']);
		$groupAdditions = array_diff($newStorage['applicableGroups'], $oldStorage['applicableGroups']);
		$groupDeletions = array_diff($oldStorage['applicableGroups'], $newStorage['applicableGroups']);

		// if no applicable were set, raise a signal for "all"
		if (empty($oldStorage['applicableUsers']) && empty($oldStorage['applicableGroups'])) {
			$this->triggerApplicableHooks(
				Filesystem::signal_delete_mount,
				$oldStorage['mountPoint'],
				\OC_Mount_Config::MOUNT_TYPE_USER,
				['all']
			);
		}

		// trigger delete for removed users
		$this->triggerApplicableHooks(
			Filesystem::signal_delete_mount,
			$oldStorage['mountPoint'],
			\OC_Mount_Config::MOUNT_TYPE_USER,
			$userDeletions
		);

		// trigger delete for removed groups
		$this->triggerApplicableHooks(
			Filesystem::signal_delete_mount,
			$oldStorage['mountPoint'],
			\OC_Mount_Config::MOUNT_TYPE_GROUP,
			$groupDeletions
		);

		// and now add the new users
		$this->triggerApplicableHooks(
			Filesystem::signal_create_mount,
			$oldStorage['mountPoint'],
			\OC_Mount_Config::MOUNT_TYPE_USER,
			$userAdditions
		);

		// and now add the new groups
		$this->triggerApplicableHooks(
			Filesystem::signal_create_mount,
			$oldStorage['mountPoint'],
			\OC_Mount_Config::MOUNT_TYPE_GROUP,
			$groupAdditions
		);

		// if no applicable, raise a signal for "all"
		if (empty($newStorage['applicableUsers']) && empty($newStorage['applicableGroups'])) {
			$this->triggerApplicableHooks(
				Filesystem::signal_create_mount,
				$oldStorage['mountPoint'],
				\OC_Mount_Config::MOUNT_TYPE_USER,
				['all']
			);
		}
	}
}
