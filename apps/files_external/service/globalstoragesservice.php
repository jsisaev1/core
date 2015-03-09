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
}
