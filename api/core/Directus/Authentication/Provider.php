<?php

namespace Directus\Authentication;

use Directus\Authentication\Exception\ExpiredTokenException;
use Directus\Authentication\Exception\InvalidInvitationCodeException;
use Directus\Authentication\Exception\InvalidTokenException;
use Directus\Authentication\Exception\InvalidUserCredentialsException;
use Directus\Authentication\Exception\UserInactiveException;
use Directus\Authentication\Exception\UserIsNotLoggedInException;
use Directus\Authentication\Exception\UserNotFoundException;
use Directus\Authentication\User\Provider\UserProviderInterface;
use Directus\Authentication\User\UserInterface;
use Directus\Exception\Exception;
use Directus\Util\ArrayUtils;
use Directus\Util\DateUtils;
use Directus\Util\JWTUtils;

class Provider
{
    /**
     * The user ID of the public API user.
     *
     * @var integer
     */
    const PUBLIC_USER_ID = 0;

    /**
     * Whether the user has been authenticated or not
     *
     * @var bool
     */
    protected $authenticated = false;

    /**
     * Authenticated user information
     *
     * @var UserInterface
     */
    protected $user;

    /**
     * User Provider
     *
     * @var UserProviderInterface
     */
    protected $userProvider;

    /**
     * @var string
     */
    protected $secretKey;

    /**
     * JWT time to live in minutes
     *
     * @var int
     */
    protected $ttl;

    public function __construct(UserProviderInterface $userProvider, array $options = [])
    {
        if (!isset($options['secret_key']) || !is_string($options['secret_key'])) {
            throw new Exception('secret key must be a string');
        }

        $ttl = ArrayUtils::get($options, 'ttl', 5);
        if (!is_numeric($ttl)) {
            throw new Exception('ttl must be a number');
        }

        $this->userProvider = $userProvider;
        $this->user = null;
        $this->secretKey = $options['secret_key'];
        $this->ttl = (int)$ttl;
    }

    /**
     * @throws UserIsNotLoggedInException
     */
    protected function enforceUserIsAuthenticated()
    {
        if (!$this->check()) {
            throw new UserIsNotLoggedInException(__t('attempting_to_inspect_the_authenticated_user_when_a_user_is_not_authenticated'));
        }
    }

    /**
     * Signing In a user
     *
     * Creating the user token and resetting previous token
     *
     * @param array $credentials
     *
     * @return UserInterface
     *
     * @throws InvalidUserCredentialsException
     * @throws UserInactiveException
     */
    public function login(array $credentials)
    {
        $email = ArrayUtils::get($credentials, 'email');
        $password = ArrayUtils::get($credentials, 'password');

        // TODO: email and password are required

        // Verify Credentials
        // TODO: Call this something else, we are not just verifying
        // we are also getting the user data
        $user = $this->verify($email, $password);

        if (!$this->isActive($user)) {
            throw new UserInactiveException();//__t('login_error_user_is_not_active'));
        }

        $this->authenticated = true;

        return $user;
    }

    /**
     * Verify if the credentials matches a user
     *
     * @param string $email
     * @param string $password
     *
     * @return UserInterface
     *
     * @throws InvalidUserCredentialsException
     */
    public function verify($email, $password)
    {
        $user = $this->user = $this->userProvider->findByEmail($email);

        // Verify that the user has an id (exists), it returns empty user object otherwise
        if (!$user || !$this->user->getId() || !password_verify($password, $this->user->get('password'))) {
            // TODO: Add exception message
            throw new InvalidUserCredentialsException();
        }

        return $user;
    }

    /**
     * Checks if the user is active
     *
     * @param UserInterface $user
     *
     * @return bool
     */
    public function isActive(UserInterface $user)
    {
        $userProvider = $this->userProvider;

        // TODO: Cast attributes values
        return $user->get('status') == $userProvider::STATUS_ACTIVE;
    }

    /**
     * Authenticate an user using a JWT Token
     *
     * @param $token
     *
     * @return UserInterface
     *
     * @throws InvalidTokenException
     */
    public function authenticateWithToken($token)
    {
        $payload = JWTUtils::decode($token, $this->getSecretKey(), ['HS256']);

        $conditions = [
            'id' => $payload->id,
            'group' => $payload->group
        ];

        $this->user = $this->userProvider->findWhere($conditions);
        if ($this->user->getId() === null) {
            throw new InvalidTokenException();
        }

        $this->authenticated = true;

        return $this->user;
    }

    /**
     * Authenticate an user using a private token
     *
     * @param $token
     *
     * @return UserInterface
     *
     * @throws InvalidTokenException
     */
    public function authenticateWithPrivateToken($token)
    {
        $conditions = [
            'token' => $token
        ];

        $this->user = $this->userProvider->findWhere($conditions);
        if (!$this->user) {
            throw new InvalidTokenException();
        }

        $this->authenticated = true;

        return $this->user;
    }

    /**
     * Authenticate with an invitation
     *
     * NOTE: Would this be managed by the web app?
     *
     * @param $invitationCode
     *
     * @return UserInterface
     *
     * @throws InvalidInvitationCodeException
     */
    public function authenticateWithInvitation($invitationCode)
    {
        $user = $this->userProvider->findWhere(['invite_token' => $invitationCode]);

        if (!$user) {
            throw new InvalidInvitationCodeException();
        }

        $this->forceUserLogin($user);
        $this->user = $user;

        return $this->user;
    }

    /**
     * Force a user id to be the logged user
     *
     * TODO: Change this method name
     *
     * @param UserInterface $user The User account's ID.
     *
     * @return UserInterface
     *
     * @throws UserNotFoundException
     */
    public function forceUserLogin(UserInterface $user)
    {
        if (!$user || !$user->getId()) {
            throw new UserNotFoundException();
        }

        $this->authenticated = true;
        $this->user = $user;

        return $this->user;
    }

    /**
     * Check if a user is logged in.
     *
     * @return boolean
     */
    public function check()
    {
        return $this->authenticated;
    }

    /**
     * Retrieve metadata about the currently logged in user.
     *
     * @param null|string $attribute
     *
     * @return mixed|array Authenticated user metadata.
     *
     * @throws  \Directus\Authentication\Exception\UserIsNotLoggedInException
     */
    public function getUserAttributes($attribute = null)
    {
        $this->enforceUserIsAuthenticated();
        $user = $this->user->toArray();

        if ($attribute !== null) {
            return array_key_exists($attribute, $user) ? $user[$attribute] : null;
        }

        return $user;
    }

    /**
     * Gets authenticated user object
     *
     * @return UserInterface|null
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Generates a new access token
     *
     * @param UserInterface $user
     *
     * @return string
     */
    public function generateToken(UserInterface $user)
    {
        // TODO: Allow customization of these values

        $algo = 'HS256';
        // TODO: Parse data types
        $payload = [
            'id' => (int) $user->getId(),
            'group' => (int) $user->getGroupId(),
            'exp' => $this->getNewExpirationTime()
        ];

        return JWTUtils::encode($payload, $this->getSecretKey(), $algo);
    }

    /**
     * Refresh valid token expiration
     *
     * @param $token
     *
     * @return string
     *
     * @throws ExpiredTokenException
     * @throws InvalidTokenException
     */
    public function refreshToken($token)
    {
        $algo = 'HS256';
        $payload = JWTUtils::decode($token, $this->getSecretKey(), [$algo]);

        if (!is_object($payload)) {
            // Empty payload, log this as debug?
            throw new InvalidTokenException();
        }

        $payload->exp = $this->getNewExpirationTime();

        return JWTUtils::encode($payload, $this->getSecretKey(), $algo);
    }

    /**
     * Run the hashing algorithm on a password and salt value.
     *
     * @param  string $password
     *
     * @return string
     */
    public function hashPassword($password)
    {
        // TODO: Create a library to hash/verify passwords up to the user which algorithm to use
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * Authentication secret key
     *
     * @return string
     */
    public function getSecretKey()
    {
        return $this->secretKey;
    }

    /**
     * @return int
     */
    public function getNewExpirationTime()
    {
        return time() + ($this->ttl * DateUtils::MINUTE_IN_SECONDS);
    }
}
