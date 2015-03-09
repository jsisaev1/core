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
 * Service class to manage user external storages
 * (aka personal storages)
 */
class UserStoragesService extends StoragesService {
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
	 * Read legacy config data
	 *
	 * @return array list of mount configs
	 */
	protected function readLegacyConfig() {
		// read user config
		$user = $this->userSession->getUser()->getUID();
		return \OC_Mount_Config::readData($user);
	}

	/**
	 * Read the external storages config
	 *
	 * @return array map of storage id to storage config
	 */
	protected function readConfig() {
		$user = $this->userSession->getUser()->getUID();
		$storages = parent::readConfig();

		$filteredStorages = [];
		foreach ($storages as $configId => $storage) {
			// filter out all bogus storages that aren't for the current user
			if (!in_array($user, $storage['applicableUsers'])) {
				continue;
			}

			// strip out unneeded applicableUser fields
			unset($storage['applicableUsers']);
			unset($storage['applicableGroups']);
			$filteredStorages[$configId] = $storage;
		}

		return $filteredStorages;
	}

	/**
	 * Write the storages to the user's configuration.
	 *
	 * @param array $storages map of storage id to storage config
	 */
	public function writeConfig($storages) {
		$user = $this->userSession->getUser()->getUID();

		// let the horror begin
		$mountPoints = [];
		foreach ($storages as $storageConfig) {
			$mountPoint = $storageConfig['mountPoint'];
			$storageConfig['backendOptions'] = \OC_Mount_Config::encryptPasswords($storageConfig['backendOptions']);

			$rootMountPoint = '/' . $user . '/files/' . ltrim($mountPoint, '/');

			$this->addMountPoint(
				$mountPoints,
				\OC_Mount_Config::MOUNT_TYPE_USER,
				$user,
				$rootMountPoint,
				$storageConfig
			);
		}

		\OC_Mount_Config::writeData($user, $mountPoints);
	}

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
			true
		);

		return $storage;
	}
}
