<?php
use \Chibi\Sql as Sql;

final class UserEntity extends AbstractEntity implements IValidatable, ISerializable
{
	private $name;
	private $passSalt;
	private $passHash;
	private $staffConfirmed;
	private $emailUnconfirmed;
	private $emailConfirmed;
	private $joinDate;
	private $lastLoginDate;
	private $accessRank;
	private $banned = false;
	private $avatarStyle;

	private $settings;
	private $_passwordChanged = false;
	private $_password;

	public function fillNew()
	{
		$this->setAccessRank(new AccessRank(AccessRank::Anonymous));
		$this->setPasswordSalt(md5(mt_rand() . uniqid()));
		$this->avatarStyle = new UserAvatarStyle(UserAvatarStyle::Gravatar);
		$this->settings = new UserSettings();
	}

	public function fillFromDatabase($row)
	{
		$this->id = (int) $row['id'];
		$this->name = $row['name'];
		$this->passSalt = $row['pass_salt'];
		$this->passHash = $row['pass_hash'];
		$this->staffConfirmed = $row['staff_confirmed'];
		$this->emailUnconfirmed = $row['email_unconfirmed'];
		$this->emailConfirmed = $row['email_confirmed'];
		$this->joinDate = TextHelper::toIntegerOrNull($row['join_date']);
		$this->lastLoginDate = TextHelper::toIntegerOrNull($row['last_login_date']);
		$this->banned = $row['banned'];
		$this->setAccessRank(new AccessRank($row['access_rank']));
		$this->avatarStyle = new UserAvatarStyle($row['avatar_style']);
		$this->settings = new UserSettings($row['settings']);
	}

	public function serializeToArray()
	{
		return
		[
			'name' => $this->getName(),
			'join-time' => $this->getJoinTime(),
			'last-login-time' => $this->getLastLoginTime(),
			'access-rank' => $this->getAccessRank()->toInteger(),
			'is-banned' => $this->isBanned(),
		];
	}

	public function validate()
	{
		$this->validateUserName();
		$this->validatePassword();
		$this->validateAccessRank();
		$this->validateEmails();
		$this->settings->validate();
		$this->avatarStyle->validate();

		if (empty($this->getAccessRank()))
			throw new Exception('No access rank detected');

		if ($this->getAccessRank()->toInteger() == AccessRank::Anonymous)
			throw new Exception('Trying to save anonymous user into database');
	}

	private function validateUserName()
	{
		$userName = $this->getName();
		$config = Core::getConfig();

		$otherUser = UserModel::tryGetByName($userName);
		if ($otherUser !== null and $otherUser->getId() != $this->getId())
		{
			$this->throwDuplicateUserError($otherUser, 'name');
		}

		$userNameMinLength = intval($config->registration->userNameMinLength);
		$userNameMaxLength = intval($config->registration->userNameMaxLength);
		$userNameRegex = $config->registration->userNameRegex;

		if (strlen($userName) < $userNameMinLength)
			throw new SimpleException('User name must have at least %d characters', $userNameMinLength);

		if (strlen($userName) > $userNameMaxLength)
			throw new SimpleException('User name must have at most %d characters', $userNameMaxLength);

		if (!preg_match($userNameRegex, $userName))
			throw new SimpleException('User name contains invalid characters');
	}

	private function validatePassword()
	{
		if (empty($this->getPasswordHash()))
			throw new Exception('Trying to save user with no password into database');

		if (!$this->_passwordChanged)
			return;

		$config = Core::getConfig();
		$passMinLength = intval($config->registration->passMinLength);
		$passRegex = $config->registration->passRegex;

		$password = $this->_password;

		if (strlen($password) < $passMinLength)
			throw new SimpleException('Password must have at least %d characters', $passMinLength);

		if (!preg_match($passRegex, $password))
			throw new SimpleException('Password contains invalid characters');
	}

	private function validateAccessRank()
	{
		$this->accessRank->validate();

		if ($this->accessRank->toInteger() == AccessRank::Nobody)
			throw new Exception(sprintf('Cannot set special access rank "%s"', $this->accessRank->toDisplayString()));
	}

	private function validateEmails()
	{
		$this->validateEmail($this->getUnconfirmedEmail());
		$this->validateEmail($this->getConfirmedEmail());
	}

	private function validateEmail($email)
	{
		if (!empty($email) and !TextHelper::isValidEmail($email))
			throw new SimpleException('E-mail address appears to be invalid');

		$otherUser = UserModel::tryGetByEmail($email);
		if ($otherUser !== null and $otherUser->getId() != $this->getId())
		{
			$this->throwDuplicateUserError($otherUser, 'e-mail');
		}
	}

	private function throwDuplicateUserError($otherUser, $reason)
	{
		$config = Core::getConfig();

		if (!$otherUser->getConfirmedEmail()
			and isset($config->registration->needEmailForRegistering)
			and $config->registration->needEmailForRegistering)
		{
			throw new SimpleException(
				'User with this %s is already registered and awaits e-mail confirmation',
				$reason);
		}

		if (!$otherUser->isStaffConfirmed()
			and $config->registration->staffActivation)
		{
			throw new SimpleException(
				'User with this %s is already registered and awaits staff confirmation',
				$reason);
		}

		throw new SimpleException(
			'User with this %s is already registered',
			$reason);
	}

	public function isBanned()
	{
		return $this->banned;
	}

	public function ban()
	{
		$this->banned = true;
	}

	public function unban()
	{
		$this->banned = false;
	}

	public function getName()
	{
		return $this->name;
	}

	public function setName($name)
	{
		$this->name = $name === null ? null : trim($name);
	}

	public function getJoinTime()
	{
		return $this->joinDate;
	}

	public function setJoinTime($unixTime)
	{
		$this->joinDate = $unixTime;
	}

	public function getLastLoginTime()
	{
		return $this->lastLoginDate;
	}

	public function setLastLoginTime($unixTime)
	{
		$this->lastLoginDate = $unixTime;
	}

	public function getUnconfirmedEmail()
	{
		return $this->emailUnconfirmed;
	}

	public function setUnconfirmedEmail($email)
	{
		$this->emailUnconfirmed = $email === null ? null : trim($email);
	}

	public function getConfirmedEmail()
	{
		return $this->emailConfirmed;
	}

	public function setConfirmedEmail($email)
	{
		$this->emailConfirmed = $email === null ? null : trim($email);
	}

	public function isStaffConfirmed()
	{
		return $this->staffConfirmed;
	}

	public function setStaffConfirmed($confirmed)
	{
		$this->staffConfirmed = $confirmed;
	}

	public function getPasswordHash()
	{
		return $this->passHash;
	}

	public function getPasswordSalt()
	{
		return $this->passSalt;
	}

	public function setPasswordSalt($passSalt)
	{
		$this->passSalt = $passSalt;
		$this->passHash = null;
	}

	public function setPassword($password)
	{
		$this->_passwordChanged = true;
		$this->_password = $password;
		$this->passHash = UserModel::hashPassword($password, $this->passSalt);
	}

	public function getAccessRank()
	{
		return $this->accessRank;
	}

	public function setAccessRank(AccessRank $accessRank)
	{
		$accessRank->validate();
		$this->accessRank = $accessRank;
	}

	public function getAvatarStyle()
	{
		return $this->avatarStyle;
	}

	public function setAvatarStyle(UserAvatarStyle $userAvatarStyle)
	{
		$this->avatarStyle = $userAvatarStyle;
	}

	public function getAvatarUrl($size = 32)
	{
		switch ($this->avatarStyle->toInteger())
		{
			case UserAvatarStyle::None:
				return $this->getBlankAvatarUrl($size);

			case UserAvatarStyle::Gravatar:
				return $this->getGravatarAvatarUrl($size);

			case UserAvatarStyle::Custom:
				return $this->getCustomAvatarUrl($size);
		}
	}

	public function setCustomAvatarFromPath($srcPath)
	{
		$config = Core::getConfig();

		$mimeType = mime_content_type($srcPath);
		if (!in_array($mimeType, ['image/gif', 'image/png', 'image/jpeg']))
			throw new SimpleException('Invalid file type "%s"', $mimeType);

		$dstPath = $this->getCustomAvatarSourcePath();

		TransferHelper::copy($srcPath, $dstPath);
		$this->removeOldCustomAvatar();
		$this->setAvatarStyle(new UserAvatarStyle(UserAvatarStyle::Custom));
	}

	public function getSettings()
	{
		return $this->settings;
	}

	public function confirmEmail()
	{
		if (empty($this->getUnconfirmedEmail()))
			return;

		$this->setConfirmedEmail($this->getUnconfirmedEmail());
		$this->setUnconfirmedEmail(null);
	}

	public function hasFavorited($post)
	{
		$stmt = \Chibi\Sql\Statements::select();
		$stmt->setColumn(Sql\Functors::alias(Sql\Functors::count('1'), 'count'));
		$stmt->setTable('favoritee');
		$stmt->setCriterion(Sql\Functors::conjunction()
			->add(Sql\Functors::equals('user_id', new Sql\Binding($this->getId())))
			->add(Sql\Functors::equals('post_id', new Sql\Binding($post->getId()))));
		return Core::getDatabase()->fetchOne($stmt)['count'] == 1;
	}

	public function getScore($post)
	{
		$stmt = \Chibi\Sql\Statements::select();
		$stmt->setColumn('score');
		$stmt->setTable('post_score');
		$stmt->setCriterion(Sql\Functors::conjunction()
			->add(Sql\Functors::equals('user_id', new Sql\Binding($this->getId())))
			->add(Sql\Functors::equals('post_id', new Sql\Binding($post->getId()))));
		$row = Core::getDatabase()->fetchOne($stmt);
		if ($row)
			return intval($row['score']);
		return null;
	}

	public function getFavoriteCount()
	{
		$stmt = \Chibi\Sql\Statements::select();
		$stmt->setColumn(Sql\Functors::alias(Sql\Functors::count('1'), 'count'));
		$stmt->setTable('favoritee');
		$stmt->setCriterion(Sql\Functors::equals('user_id', new Sql\Binding($this->getId())));
		return (int) Core::getDatabase()->fetchOne($stmt)['count'];
	}

	public function getCommentCount()
	{
		$stmt = \Chibi\Sql\Statements::select();
		$stmt->setColumn(Sql\Functors::alias(Sql\Functors::count('1'), 'count'));
		$stmt->setTable('comment');
		$stmt->setCriterion(Sql\Functors::equals('commenter_id', new Sql\Binding($this->getId())));
		return (int) Core::getDatabase()->fetchOne($stmt)['count'];
	}

	public function getPostCount()
	{
		$stmt = \Chibi\Sql\Statements::select();
		$stmt->setColumn(Sql\Functors::alias(Sql\Functors::count('1'), 'count'));
		$stmt->setTable('post');
		$stmt->setCriterion(Sql\Functors::equals('uploader_id', new Sql\Binding($this->getId())));
		return (int) Core::getDatabase()->fetchOne($stmt)['count'];
	}


	private function getBlankAvatarUrl($size)
	{
		return 'http://www.gravatar.com/avatar/?s=' . $size . '&d=mm';
	}

	private function getGravatarAvatarUrl($size)
	{
		$subject = !empty($this->getConfirmedEmail())
			? $this->getConfirmedEmail()
			: $this->passSalt . $this->getName();
		$hash = md5(strtolower(trim($subject)));
		return 'http://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&d=retro';
	}

	private function getCustomAvatarUrl($size)
	{
		$fileName = md5($this->getName()) . '-' . $size . '.avatar';
		$path = $this->getCustomAvatarPath($size);
		if (!file_exists($path))
			$this->generateCustomAvatar($size);
		if (file_exists($path))
			return \Chibi\Util\Url::makeAbsolute('/avatars/' . $fileName);
		return $this->getBlankAvatarUrl($size);
	}

	private function getCustomAvatarSourcePath()
	{
		$fileName = md5($this->getName()) . '.avatar_source';
		return Core::getConfig()->main->avatarsPath . DS . $fileName;
	}

	private function getCustomAvatarPath($size)
	{
		$fileName = md5($this->getName()) . '-' . $size . '.avatar';
		return Core::getConfig()->main->avatarsPath . DS . $fileName;
	}

	private function getCustomAvatarPaths()
	{
		$hash = md5($this->getName());
		return glob(Core::getConfig()->main->avatarsPath . DS . $hash . '*.avatar');
	}

	private function removeOldCustomAvatar()
	{
		foreach ($this->getCustomAvatarPaths() as $path)
			TransferHelper::remove($path);
	}

	private function generateCustomAvatar($size)
	{
		$srcPath = $this->getCustomAvatarSourcePath($size);
		$dstPath = $this->getCustomAvatarPath($size);

		$thumbnailGenerator = new ImageThumbnailGenerator();
		return $thumbnailGenerator->generateFromFile(
			$srcPath,
			$dstPath,
			$size,
			$size);
	}
}
