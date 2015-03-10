<?php
/**
 * ownCloud
 *
 * @author Vincent Petry
 * Copyright (c) 2015 Vincent Petry <pvince81@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Files_external\Tests\Service;

use \OCA\Files_external\Service\GlobalStoragesService;
use \OCA\Files_external\NotFoundException;

class GlobalStoragesServiceTest extends \Test\TestCase {

	/**
	 * @var GlobalStoragesService
	 */
	private $service;

	/**
	 * Data directory
	 *
	 * @var string
	 */
	private $dataDir;

	public function setUp() {
		$this->service = new GlobalStoragesService();
		$config = \OC::$server->getConfig();
		$this->dataDir = $config->getSystemValue(
			'datadirectory',
			\OC::$SERVERROOT . '/data/'
		);
		\OC_Mount_Config::$skipTest = true;
	}

	public function tearDown() {
		\OC_Mount_Config::$skipTest = false;
		@unlink($this->dataDir . '/mount.json');
	}

	private function makeTestStorageData() {
		return array(
			'mountPoint' => 'mountpoint',
			'backendClass' => '\OC\Files\Storage\SMB',
			'backendOptions' => array(
				'option1' => 'value1',
				'option2' => 'value2',
				'password' => 'testPassword',
			),
			'applicableUsers' => [],
			'applicableGroups' => [],
			'priority' => 15,
		);
	}

	public function testAddStorage() {
		$storage = $this->makeTestStorageData();

		$newStorage = $this->service->addStorage($storage);

		$this->assertEquals(1, $newStorage['id']);


		$newStorage = $this->service->getStorage(1);

		$this->assertEquals($storage['mountPoint'], $newStorage['mountPoint']);
		$this->assertEquals($storage['backendClass'], $newStorage['backendClass']);
		$this->assertEquals($storage['backendOptions'], $newStorage['backendOptions']);
		$this->assertEquals($storage['applicableUsers'], $newStorage['applicableUsers']);
		$this->assertEquals($storage['applicableGroups'], $newStorage['applicableGroups']);
		$this->assertEquals($storage['priority'], $newStorage['priority']);
		$this->assertEquals(1, $newStorage['id']);
		$this->assertEquals(0, $newStorage['status']);

		// next one gets id 2
		$storage['applicableUsers'] = ['user1', 'user2'];
		$storage['applicableGroups'] = ['group1', 'group2'];
		$nextStorage = $this->service->addStorage($storage);
		$this->assertEquals(2, $nextStorage['id']);
		$this->assertEquals(['user1', 'user2'], $nextStorage['applicableUsers']);
		$this->assertEquals(['group1', 'group2'], $nextStorage['applicableGroups']);
	}

	public function testUpdateStorage() {
		$storage = array(
			'mountPoint' => 'mountpoint',
			'backendClass' => '\OC\Files\Storage\SMB',
			'backendOptions' => array(
				'option1' => 'value1',
				'option2' => 'value2',
				'password' => 'testPassword',
			),
			'applicableUsers' => [],
			'applicableGroups' => [],
			'priority' => 15,
		);

		$newStorage = $this->service->addStorage($storage);
		$this->assertEquals(1, $newStorage['id']);

		$newStorage['mountPoint'] = 'mountPoint2';
		$newStorage['backendOptions']['password'] = 'anotherPassword';
		$newStorage['applicableUsers'][] = 'user1';
		$newStorage['applicableGroups'][] = 'group1';

		$newStorage = $this->service->updateStorage($newStorage);

		$this->assertEquals('mountPoint2', $newStorage['mountPoint']);
		$this->assertEquals('anotherPassword', $newStorage['backendOptions']['password']);
		$this->assertEquals(['user1'], $newStorage['applicableUsers']);
		$this->assertEquals(['group1'], $newStorage['applicableGroups']);
		$this->assertEquals(15, $newStorage['priority']);
		$this->assertEquals(1, $newStorage['id']);
		$this->assertEquals(0, $newStorage['status']);
	}

	/**
	 * @expectedException \OCA\Files_external\NotFoundException
	 */
	public function testNonExistingStorage() {
		$storage = array(
			'id' => '255',
			'mountPoint' => 'mountpoint',
			'backendClass' => '\OC\Files\Storage\SMB',
			'backendOptions' => array(),
		);
		$this->service->updateStorage($storage);
	}

	public function testDeleteStorage() {
		$storage = array(
			'mountPoint' => 'mountpoint',
			'backendClass' => '\OC\Files\Storage\SMB',
			'backendOptions' => array(
				'password' => 'testPassword',
			),
		);

		$newStorage = $this->service->addStorage($storage);
		$this->assertEquals(1, $newStorage['id']);

		$newStorage = $this->service->removeStorage(1);

		$caught = false;
		try {
			$this->service->getStorage(1);
		} catch (NotFoundException $e) {
			$caught = true;
		}

		$this->assertTrue($caught);
	}

	/**
	 * @expectedException \OCA\Files_external\NotFoundException
	 */
	public function testDeleteUnexistingStorage() {
		$this->service->removeStorage(255);
	}

	public function testHooks() {
		// TODO
	}

	/**
	 * Make sure it uses the correct format when reading/writing
	 * the legacy config
	 */
	public function testLegacyConfigConversionApplicableAll() {
		$configFile = $this->dataDir . '/mount.json';

		$storage = $this->makeTestStorageData();
		$storage = $this->service->addStorage($storage);

		$json = json_decode(file_get_contents($configFile), true);

		$this->assertCount(1, $json);

		$this->assertEquals([\OC_Mount_Config::MOUNT_TYPE_USER], array_keys($json));
		$this->assertEquals(['all'], array_keys($json[\OC_Mount_config::MOUNT_TYPE_USER]));

		$mountPointData = $json[\OC_Mount_config::MOUNT_TYPE_USER]['all'];
		$this->assertEquals(['/$user/files/mountpoint'], array_keys($mountPointData));

		$mountPointOptions = current($mountPointData);
		$this->assertEquals(1, $mountPointOptions['id']);
		$this->assertEquals('\OC\Files\Storage\SMB', $mountPointOptions['class']);
		$this->assertEquals(15, $mountPointOptions['priority']);

		$backendOptions = $mountPointOptions['options'];
		$this->assertEquals('value1', $backendOptions['option1']);
		$this->assertEquals('value2', $backendOptions['option2']);
		$this->assertEquals('', $backendOptions['password']);
		$this->assertNotEmpty($backendOptions['password_encrypted']);
	}

	/**
	 * Make sure it uses the correct format when reading/writing
	 * the legacy config
	 */
	public function testLegacyConfigConversionApplicableUserAndGroup() {
		$configFile = $this->dataDir . '/mount.json';

		$storage = $this->makeTestStorageData();
		$storage['applicableUsers'] = ['user1', 'user2'];
		$storage['applicableGroups'] = ['group1', 'group2'];

		$storage = $this->service->addStorage($storage);

		$json = json_decode(file_get_contents($configFile), true);

		$this->assertCount(2, $json);

		$this->assertTrue(isset($json[\OC_Mount_Config::MOUNT_TYPE_USER]));
		$this->assertTrue(isset($json[\OC_Mount_Config::MOUNT_TYPE_GROUP]));
		$this->assertEquals(['user1', 'user2'], array_keys($json[\OC_Mount_config::MOUNT_TYPE_USER]));
		$this->assertEquals(['group1', 'group2'], array_keys($json[\OC_Mount_config::MOUNT_TYPE_GROUP]));

		// check that all options are the same for both users and both groups
		foreach ($json[\OC_Mount_Config::MOUNT_TYPE_USER] as $mountPointData) {
			$this->assertEquals(['/$user/files/mountpoint'], array_keys($mountPointData));

			$mountPointOptions = current($mountPointData);

			$this->assertEquals(1, $mountPointOptions['id']);
			$this->assertEquals('\OC\Files\Storage\SMB', $mountPointOptions['class']);
			$this->assertEquals(15, $mountPointOptions['priority']);

			$backendOptions = $mountPointOptions['options'];
			$this->assertEquals('value1', $backendOptions['option1']);
			$this->assertEquals('value2', $backendOptions['option2']);
			$this->assertEquals('', $backendOptions['password']);
			$this->assertNotEmpty($backendOptions['password_encrypted']);
		}

		foreach ($json[\OC_Mount_Config::MOUNT_TYPE_GROUP] as $mountPointData) {
			$this->assertEquals(['/$user/files/mountpoint'], array_keys($mountPointData));

			$mountPointOptions = current($mountPointData);

			$this->assertEquals(1, $mountPointOptions['id']);
			$this->assertEquals('\OC\Files\Storage\SMB', $mountPointOptions['class']);
			$this->assertEquals(15, $mountPointOptions['priority']);

			$backendOptions = $mountPointOptions['options'];
			$this->assertEquals('value1', $backendOptions['option1']);
			$this->assertEquals('value2', $backendOptions['option2']);
			$this->assertEquals('', $backendOptions['password']);
			$this->assertNotEmpty($backendOptions['password_encrypted']);
		}
	}

}
