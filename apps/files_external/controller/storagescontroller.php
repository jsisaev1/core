<?php
/**
 * ownCloud - files_external
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Vincent Petry <pvince81@owncloud.com>
 * @copyright Vincent Petry 2014
 */

namespace OCA\Files_External\Controller;


use \OCP\IConfig;
use \OCP\IUserSession;
use \OCP\IRequest;
use \OCP\AppFramework\Http\DataResponse;
use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http;
use \OCA\Files_external\Service\StoragesService;
use \OCA\Files_external\NotFoundException;

abstract class StoragesController extends Controller {

	/**
	 * @var \OCP\IL10N
	 */
	protected $l10n;

	/**
	 * @var StoragesService
	 */
	protected $service;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param StoragesService $storagesService
	 */
	public function __construct(
		$AppName,
		IRequest $request,
		\OCP\IL10N $l10n,
		StoragesService $storagesService
	){
		parent::__construct($AppName, $request);
		$this->l10n = $l10n;
		$this->service = $storagesService;
	}

	/**
	 * Validate storage config
	 *
	 * @param array $storage storage config
	 *
	 * @return DataResponse|null returns response in case of validation error
	 */
	protected function validate($storage) {
		$mountPoint = \OC\Files\Filesystem::normalizePath($storage['mountPoint']);
		if ($mountPoint === '' || $mountPoint === '/') {
			return new DataResponse(
				array(
					'message' => (string)$this->l10n->t('Invalid mount point')
				),
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		// TODO: validate that other attrs are set

		$backends = \OC_Mount_Config::getBackends();
		if (!isset($backends[$storage['backendClass']])) {
			// invalid backend
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
	 * Get an external storage entry.
	 *
	 * @param int $id storage id
	 *
	 * @return DataResponse
	 */
	public function show($id) {
		try {
			$storage = $this->service->getStorage($id);
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
			Http::STATUS_OK
		);
	}

	/**
	 * Deletes the storage with the given id.
	 *
	 * @param int $id storage id
	 *
	 * @return DataResponse
	 */
	public function destroy($id) {
		try {
			$this->service->removeStorage($id);
		} catch (NotFoundException $e) {
			return new DataResponse(
				[
					'message' => (string)$this->l10n->t('Storage with id "%i" not found', array($id))
				],
				Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

}

