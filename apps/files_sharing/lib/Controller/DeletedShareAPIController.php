<?php

namespace OCA\Files_Sharing\Controller;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;

class DeletedShareAPIController extends OCSController {

	/** @var ShareManager */
	private $shareManager;

	/** @var string */
	private $userId;

	/** @var IUserManager */
	private $userManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(string $appName,
								IRequest $request,
								ShareManager $shareManager,
								string $UserId,
								IUserManager $userManager,
								IGroupManager $groupManager,
								IRootFolder $rootFolder) {
		parent::__construct($appName, $request);

		$this->shareManager = $shareManager;
		$this->userId = $UserId;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->rootFolder = $rootFolder;
	}

	private function formatShare(IShare $share): array {

		$result = [
			'id' => $share->getFullId(),
			'share_type' => $share->getShareType(),
			'uid_owner' => $share->getSharedBy(),
			'displayname_owner' => $this->userManager->get($share->getSharedBy())->getDisplayName(),
			'permissions' => $share->getPermissions(),
			'stime' => $share->getShareTime()->getTimestamp(),
			'parent' => null,
			'expiration' => null,
			'token' => null,
			'uid_file_owner' => $share->getShareOwner(),
			'displayname_file_owner' => $this->userManager->get($share->getShareOwner())->getDisplayName(),
			'path' => $share->getTarget(),
		];
		$userFolder = $this->rootFolder->getUserFolder($share->getSharedBy());
		$nodes = $userFolder->getById($share->getNodeId());
		if (empty($nodes)) {
			// fallback to guessing the path
			$node = $userFolder->get($share->getTarget());
			if ($node === null || $share->getTarget() === '') {
				throw new NotFoundException();
			}
		} else {
			$node = $nodes[0];
		}

		$result['path'] = $userFolder->getRelativePath($node->getPath());
		if ($node instanceOf \OCP\Files\Folder) {
			$result['item_type'] = 'folder';
		} else {
			$result['item_type'] = 'file';
		}
		$result['mimetype'] = $node->getMimetype();
		$result['storage_id'] = $node->getStorage()->getId();
		$result['storage'] = $node->getStorage()->getCache()->getNumericStorageId();
		$result['item_source'] = $node->getId();
		$result['file_source'] = $node->getId();
		$result['file_parent'] = $node->getParent()->getId();
		$result['file_target'] = $share->getTarget();

		$expiration = $share->getExpirationDate();
		if ($expiration !== null) {
			$result['expiration'] = $expiration->format('Y-m-d 00:00:00');
		}

		$group = $this->groupManager->get($share->getSharedWith());
		$result['share_with'] = $share->getSharedWith();
		$result['share_with_displayname'] = $group !== null ? $group->getDisplayName() : $share->getSharedWith();

		return $result;

	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		$shares = $this->shareManager->getDeletedSharedWith($this->userId, \OCP\Share::SHARE_TYPE_GROUP, null, -1, 0);

		$shares = array_map(function (IShare $share) {
			return $this->formatShare($share);
		}, $shares);

		return new DataResponse($shares);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @throws OCSException
	 */
	public function undelete(string $id): DataResponse {
		try {
			$share = $this->shareManager->getShareById($id, $this->userId);
		} catch (ShareNotFound $e) {
			throw new OCSNotFoundException('Share not found');
		}

		if ($share->getPermissions() !== 0) {
			throw new OCSNotFoundException('No deleted share found');
		}

		try {
			$this->shareManager->restoreShare($share, $this->userId);
		} catch (GenericShareException $e) {
			throw new OCSException('Something went wrong');
		}

		return new DataResponse([]);
	}
}
