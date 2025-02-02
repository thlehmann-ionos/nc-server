<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Tests\unit\SystemTag;

use OC\SystemTag\SystemTag;
use OCA\DAV\SystemTag\SystemTagNode;
use OCP\IUser;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;
use Sabre\DAV\Exception\Forbidden;

class SystemTagNodeTest extends \Test\TestCase {

	/**
	 * @var ISystemTagManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $tagManager;

	/**
	 * @var IUser
	 */
	private $user;

	protected function setUp(): void {
		parent::setUp();

		$this->tagManager = $this->getMockBuilder(ISystemTagManager::class)
			->getMock();
		$this->user = $this->getMockBuilder(IUser::class)
			->getMock();
	}

	protected function getTagNode($isAdmin = true, $tag = null) {
		if ($tag === null) {
			$tag = new SystemTag(1, 'Test', true, true);
		}
		return new SystemTagNode(
			$tag,
			$this->user,
			$isAdmin,
			$this->tagManager
		);
	}

	public function adminFlagProvider() {
		return [[true], [false]];
	}

	/**
	 * @dataProvider adminFlagProvider
	 */
	public function testGetters($isAdmin): void {
		$tag = new SystemTag('1', 'Test', true, true);
		$node = $this->getTagNode($isAdmin, $tag);
		$this->assertEquals('1', $node->getName());
		$this->assertEquals($tag, $node->getSystemTag());
	}


	public function testSetName(): void {
		$this->expectException(\Sabre\DAV\Exception\MethodNotAllowed::class);

		$this->getTagNode()->setName('2');
	}

	public function tagNodeProvider() {
		return [
			// admin
			[
				true,
				new SystemTag(1, 'Original', true, true),
				['Renamed', true, true]
			],
			[
				true,
				new SystemTag(1, 'Original', true, true),
				['Original', false, false]
			],
			// non-admin
			[
				// renaming allowed
				false,
				new SystemTag(1, 'Original', true, true),
				['Rename', true, true]
			],
		];
	}

	/**
	 * @dataProvider tagNodeProvider
	 */
	public function testUpdateTag($isAdmin, ISystemTag $originalTag, $changedArgs): void {
		$this->tagManager->expects($this->once())
			->method('canUserSeeTag')
			->with($originalTag)
			->willReturn($originalTag->isUserVisible() || $isAdmin);
		$this->tagManager->expects($this->once())
			->method('canUserAssignTag')
			->with($originalTag)
			->willReturn($originalTag->isUserAssignable() || $isAdmin);
		$this->tagManager->expects($this->once())
			->method('updateTag')
			->with(1, $changedArgs[0], $changedArgs[1], $changedArgs[2]);
		$this->getTagNode($isAdmin, $originalTag)
			->update($changedArgs[0], $changedArgs[1], $changedArgs[2]);
	}

	public function tagNodeProviderPermissionException() {
		return [
			[
				// changing permissions not allowed
				new SystemTag(1, 'Original', true, true),
				['Original', false, true],
				'Sabre\DAV\Exception\Forbidden',
			],
			[
				// changing permissions not allowed
				new SystemTag(1, 'Original', true, true),
				['Original', true, false],
				'Sabre\DAV\Exception\Forbidden',
			],
			[
				// changing permissions not allowed
				new SystemTag(1, 'Original', true, true),
				['Original', false, false],
				'Sabre\DAV\Exception\Forbidden',
			],
			[
				// changing non-assignable not allowed
				new SystemTag(1, 'Original', true, false),
				['Rename', true, false],
				'Sabre\DAV\Exception\Forbidden',
			],
			[
				// changing non-assignable not allowed
				new SystemTag(1, 'Original', true, false),
				['Original', true, true],
				'Sabre\DAV\Exception\Forbidden',
			],
			[
				// invisible tag does not exist
				new SystemTag(1, 'Original', false, false),
				['Rename', false, false],
				'Sabre\DAV\Exception\NotFound',
			],
		];
	}

	/**
	 * @dataProvider tagNodeProviderPermissionException
	 */
	public function testUpdateTagPermissionException(ISystemTag $originalTag, $changedArgs, $expectedException = null): void {
		$this->tagManager->expects($this->any())
			->method('canUserSeeTag')
			->with($originalTag)
			->willReturn($originalTag->isUserVisible());
		$this->tagManager->expects($this->any())
			->method('canUserAssignTag')
			->with($originalTag)
			->willReturn($originalTag->isUserAssignable());
		$this->tagManager->expects($this->never())
			->method('updateTag');

		$thrown = null;

		try {
			$this->getTagNode(false, $originalTag)
				->update($changedArgs[0], $changedArgs[1], $changedArgs[2]);
		} catch (\Exception $e) {
			$thrown = $e;
		}

		$this->assertInstanceOf($expectedException, $thrown);
	}


	public function testUpdateTagAlreadyExists(): void {
		$this->expectException(\Sabre\DAV\Exception\Conflict::class);

		$tag = new SystemTag(1, 'tag1', true, true);
		$this->tagManager->expects($this->any())
			->method('canUserSeeTag')
			->with($tag)
			->willReturn(true);
		$this->tagManager->expects($this->any())
			->method('canUserAssignTag')
			->with($tag)
			->willReturn(true);
		$this->tagManager->expects($this->once())
			->method('updateTag')
			->with(1, 'Renamed', true, true)
			->will($this->throwException(new TagAlreadyExistsException()));
		$this->getTagNode(false, $tag)->update('Renamed', true, true);
	}


	public function testUpdateTagNotFound(): void {
		$this->expectException(\Sabre\DAV\Exception\NotFound::class);

		$tag = new SystemTag(1, 'tag1', true, true);
		$this->tagManager->expects($this->any())
			->method('canUserSeeTag')
			->with($tag)
			->willReturn(true);
		$this->tagManager->expects($this->any())
			->method('canUserAssignTag')
			->with($tag)
			->willReturn(true);
		$this->tagManager->expects($this->once())
			->method('updateTag')
			->with(1, 'Renamed', true, true)
			->will($this->throwException(new TagNotFoundException()));
		$this->getTagNode(false, $tag)->update('Renamed', true, true);
	}

	/**
	 * @dataProvider adminFlagProvider
	 */
	public function testDeleteTag($isAdmin): void {
		$tag = new SystemTag(1, 'tag1', true, true);
		$this->tagManager->expects($isAdmin ? $this->once() : $this->never())
			->method('canUserSeeTag')
			->with($tag)
			->willReturn(true);
		$this->tagManager->expects($isAdmin ? $this->once() : $this->never())
			->method('deleteTags')
			->with('1');
		if (!$isAdmin) {
			$this->expectException(Forbidden::class);
		}
		$this->getTagNode($isAdmin, $tag)->delete();
	}

	public function tagNodeDeleteProviderPermissionException() {
		return [
			[
				// cannot delete invisible tag
				new SystemTag(1, 'Original', false, true),
				'Sabre\DAV\Exception\Forbidden',
			],
			[
				// cannot delete non-assignable tag
				new SystemTag(1, 'Original', true, false),
				'Sabre\DAV\Exception\Forbidden',
			],
		];
	}

	/**
	 * @dataProvider tagNodeDeleteProviderPermissionException
	 */
	public function testDeleteTagPermissionException(ISystemTag $tag, $expectedException): void {
		$this->tagManager->expects($this->any())
			->method('canUserSeeTag')
			->with($tag)
			->willReturn($tag->isUserVisible());
		$this->tagManager->expects($this->never())
			->method('deleteTags');

		$this->expectException($expectedException);
		$this->getTagNode(false, $tag)->delete();
	}


	public function testDeleteTagNotFound(): void {
		$this->expectException(\Sabre\DAV\Exception\NotFound::class);

		$tag = new SystemTag(1, 'tag1', true, true);
		$this->tagManager->expects($this->any())
			->method('canUserSeeTag')
			->with($tag)
			->willReturn($tag->isUserVisible());
		$this->tagManager->expects($this->once())
			->method('deleteTags')
			->with('1')
			->will($this->throwException(new TagNotFoundException()));
		$this->getTagNode(true, $tag)->delete();
	}
}
