<?php
/**
 * ownCloud - files_external
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Vincent Petry <pvince81@owncloud.com>
 * @copyright Vincent Petry 2015
 */

namespace OCA\Files_External\Controller;


use \OCP\IConfig;
use \OCP\IUserSession;
use \OCP\IRequest;
use \OCP\AppFramework\Http\DataResponse;
use \OCP\AppFramework\Controller;
use OC\AppFramework\Http;
use \OCA\Files_external\Service\UserStoragesService;
use \OCA\Files_external\NotFoundException;

class UserStoragesController extends StoragesController {
	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param UserStoragesService $userStoragesService
	 */
	public function __construct(
		$AppName,
		IRequest $request,
		\OCP\IL10N $l10n,
		UserStoragesService $userStoragesService
	){
		parent::__construct(
			$AppName,
			$request,
			$l10n,
			$userStoragesService
		);
	}

	/**
	 * Validate storage config
	 *
	 * @param array $storage storage config
	 *
	 * @return DataResponse|null returns response in case of validation error
	 */
	protected function validate($storage) {
		$result = parent::validate($storage);

		if ($result != null) {
			return $result;
		}

		// Verify that the mount point applies for the current user
		// Prevent non-admin users from mounting local storage and other disabled backends
		$allowedBackends = \OC_Mount_Config::getPersonalBackends();
		if (!isset($allowedBackends[$backendClass])) {
			return new DataResponse(
				array(
					'message' => (string)$this->l10n->t('Invalid storage backend "%s"', array($backendClass))
				),
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return null;
	}

	/**
	 * @NoAdminRequired
	 * @{inheritdoc}
	 */
	public function show($id) {
		return parent::show($id);
	}

	/**
	 * Create an external storage entry.
	 *
	 * @param string $mountPoint storage mount point
	 * @param string $backendClass backend class name
	 * @param array $backendOptions backend-specific options
	 * @param array $applicableUsers users for which to mount the storage
	 * @param array $applicableGroups groups for which to mount the storage
	 * @param int $priority priority
	 *
	 * @return DataResponse
	 *
	 * @NoAdminRequired
	 */
	public function create(
		$mountPoint,
		$backendClass,
		$backendOptions,
		$priority
	) {
		$newStorage = [
			'mountPoint' => $mountPoint,
			'backendClass' => $backendClass,
			'backendOptions' => $backendOptions,
			'priority' => $priority,
		];

		$response = $this->validate($newStorage);
		if (!empty($response)) {
			return $response;
		}

		$newStorage = $this->service->addStorage($newStorage);

		return new DataResponse(
			$newStorage,
			Http::STATUS_CREATED
		);
	}

	/**
	 * Update an external storage entry.
	 *
	 * @param int $id storage id
	 * @param string $mountPoint storage mount point
	 * @param string $backendClass backend class name
	 * @param array $backendOptions backend-specific options
	 * @param int $priority priority
	 *
	 * @return DataResponse
	 */
	public function update(
		$id,
		$mountPoint,
		$backendClass,
		$backendOptions,
		$priority
	) {
		$storage = [
			'id' => $id,
			'mountPoint' => $mountPoint,
			'backendClass' => $backendClass,
			'backendOptions' => $backendOptions,
			'priority' => $priority,
		];

		$response = $this->validate($storage);
		if (!empty($response)) {
			return $response;
		}

		try {
			$storage = $this->service->updateStorage($storage);
		} catch (NotFoundException $e) {
			return new DataResponse(
				[
					'message' => (string)$this->l10n->t('Storage with id "%i" not found', array($id))
				],
				Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse(
			$storage,
			Http::STATUS_CREATED
		);

	}

	/**
	 * Deletes the storage with the given id.
	 *
	 * @param int $id storage id
	 *
	 * @return DataResponse
	 *
	 * {@inheritdoc}
	 * @NoAdminRequired
	 */
	public function destroy($id) {
		return parent::destroy($id);
	}
}

