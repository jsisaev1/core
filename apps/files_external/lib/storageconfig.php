<?php
/**
 * Copyright (c) 2015 Vincent Petry <pvince81@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Files_external\Lib;

/**
 * External storage configuration
 */
class StorageConfig implements \JsonSerializable {

	/**
	 * @var int
	 */
	private $id;

	/**
	 * @var string
	 */
	private $backendClass;

	/**
	 * @var array
	 */
	private $backendOptions = [];

	/**
	 * @var string
	 */
	private $mountPoint;

	/**
	 * @var int
	 */
	private $status;

	/**
	 * @var int
	 */
	private $priority;

	/**
	 * @var array
	 */
	private $applicableUsers = [];

	/**
	 * @var array
	 */
	private $applicableGroups = [];

	/**
	 * @param int|null $id config id or null for a new config
	 */
	public function __construct($id = null) {
		$this->id = $id;
	}

	/**
	 * Returns the configuration id
	 *
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Sets the configuration id
	 *
	 * @param int configuration id
	 */
	public function setId($id) {
		$this->id = $id;
	}

	/**
	 * Returns mount point path relative to the user's
	 * "files" folder.
	 *
	 * @return string path
	 */
	public function getMountPoint() {
		return $this->mountPoint;
	}

	/**
	 * Sets mount point path relative to the user's
	 * "files" folder.
	 * The path will be normalized.
	 *
	 * @param string path
	 */
	public function setMountPoint($mountPoint) {
		$this->mountPoint = \OC\Files\Filesystem::normalizePath($mountPoint);
	}

	/**
	 * Returns the external storage backend class name
	 *
	 * @return string external storage backend class name
	 */
	public function getBackendClass() {
		return $this->backendClass;
	}

	/**
	 * Sets the external storage backend class name
	 *
	 * @param string external storage backend class name
	 */
	public function setBackendClass($backendClass) {
		$this->backendClass = $backendClass;
	}

	/**
	 * Returns the external storage backend-specific options
	 *
	 * @return array backend options
	 */
	public function getBackendOptions() {
		return $this->backendOptions;
	}

	/**
	 * Sets the external storage backend-specific options
	 *
	 * @param array backend options
	 */
	public function setBackendOptions($backendOptions) {
		$this->backendOptions = $backendOptions;
	}

	/**
	 * Returns the mount priority
	 *
	 * @return int priority
	 */
	public function getPriority() {
		return $this->priority;
	}

	/**
	 * Sets the mount priotity
	 *
	 * @param int priority
	 */
	public function setPriority($priority) {
		$this->priority = $priority;
	}

	/**
	 * Returns the users for which to mount this storage
	 *
	 * @return array applicable users
	 */
	public function getApplicableUsers() {
		return $this->applicableUsers;
	}

	/**
	 * Sets the users for which to mount this storage
	 *
	 * @param array applicable users
	 */
	public function setApplicableUsers($applicableUsers) {
		if (is_null($applicableUsers)) {
			$applicableUsers = [];
		}
		$this->applicableUsers = $applicableUsers;
	}

	/**
	 * Returns the groups for which to mount this storage
	 *
	 * @return array applicable groups
	 */
	public function getApplicableGroups() {
		return $this->applicableGroups;
	}

	/**
	 * Sets the groups for which to mount this storage
	 *
	 * @param array applicable groups
	 */
	public function setApplicableGroups($applicableGroups) {
		if (is_null($applicableGroups)) {
			$applicableGroups = [];
		}
		$this->applicableGroups = $applicableGroups;
	}

	/**
	 * Sets the storage status, whether the config worked last time
	 *
	 * @return int $status status
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * Sets the storage status, whether the config worked last time
	 *
	 * @param int $status status
	 */
	public function setStatus($status) {
		$this->status = $status;
	}

	/**
	 * Serialize config to JSON
	 *
	 * @return array
	 */
	public function jsonSerialize() {
		$result = [];
		if (!is_null($this->id)) {
			$result['id'] = $this->id;
		}
		$result['mountPoint'] = $this->mountPoint;
		$result['backendClass'] = $this->backendClass;
		$result['backendOptions'] = $this->backendClass;
		if (!is_null($this->priority)) {
			$result['priority'] = $this->priority;
		}
		if (!empty($this->applicableUsers)) {
			$result['applicableUsers'] = $this->applicableUsers;
		}
		if (!empty($this->applicableGroups)) {
			$result['applicableGroups'] = $this->applicableGroups;
		}
		if (!is_null($this->status)) {
			$result['status'] = $this->status;
		}
		return $result;
	}
}
