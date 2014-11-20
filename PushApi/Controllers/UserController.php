<?php

namespace PushApi\Controllers;

use \PushApi\PushApiException;
use \PushApi\Models\User;
use \PushApi\Models\Channel;
use \PushApi\Models\Subscription;
use \PushApi\Controllers\Controller;
use \Illuminate\Database\QueryException;
use \Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @author Eloi Ballarà Madrid <eloi@tviso.com>
 *
 * Contains the basic and general actions that user can do.
 */
class UserController extends Controller
{
    /**
     * Creates a new user with given params and displays the information
     * of the created user. If the user tries to registrate twice (checked
     * by mail), the information of the registrated user is displayed
     * without adding him again into the registration
     */
    public function setUser()
    {
        try {
            $email = $this->slim->request->post('email');

            if (!isset($email)) {
                throw new PushApiException(PushApiException::NO_DATA);
            }

            $user = User::where('email', $email)->first();

            if (!isset($user->email)) {
                $user = new User;
                $user->email = $email;
                $user->save();
            }
        } catch (QueryException $e) {
            throw new PushApiException(PushApiException::DUPLICATED_VALUE);
        } catch (\Exception $e) {
            throw new PushApiException(PushApiException::INVALID_ACTION);
        }
        $this->send($user->toArray());
    }

    /**
     * Retrives user information if it is registered
     * @param [int] $id  User identification
     */
    public function getUser($id)
    {
        try {
            $user = User::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        }
        $this->send($user->toArray());
    }

    /**
     * Updates user infomation given its identification and params to update
     * @param [int] $id  User identification
     */
    public function updateUser($id)
    {
        try {
            $update = array();
            $update['email'] = $this->slim->request->put('email');
            $update['idandroid'] = $this->slim->request->put('idandroid');
            $update['idios'] = $this->slim->request->put('idios');
            $update['unicast'] = $this->slim->request->put('unicast');
            $update['broadcast'] = $this->slim->request->put('broadcast');

            $update = $this->cleanParams($update);

            if (empty($update)) {
                throw new PushApiException(PushApiException::NO_DATA);
            }

            $user = User::where('id', $id)->update($update);
        } catch (ModelNotFoundException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        }
        $this->send($this->boolinize($user));
    }

    /**
     * Deletes a user given its identification
     * @param [int] $id  User identification
     */
    public function deleteUser($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();
        } catch (ModelNotFoundException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        }
        $this->send($user->toArray());
    }

    /**
     * Creates new users with given params and displays the information
     * of the created user. If the user is tried to registrate twice or
     * has an invalid email, it isn't added again.
     */
    public function setUsers()
    {
        try {
            $added = array();
            $emails = $this->slim->request->post('emails');

            if (!isset($emails)) {
                throw new PushApiException(PushApiException::NO_DATA);
            }

            $emails = explode(",", $emails);

            foreach ($emails as $key => $email) {
                if (empty($email) || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $user = User::where('email', $email)->first();

                    if (!isset($user->email)) {
                        $user = new User;
                        $user->email = $email;
                        $user->save();
                        array_push($added, $user);
                    }
                }
            }
        } catch (QueryException $e) {
            throw new PushApiException(PushApiException::DUPLICATED_VALUE);
        } catch (\Exception $e) {
            throw new PushApiException(PushApiException::INVALID_ACTION);
        }
        $this->send($added);
    }

    /**
     * Retrives all users registered
     */
    public function getAllUsers()
    {
        try {
            $user = User::orderBy('id', 'asc')->get();
        } catch (ModelNotFoundException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        }

        $this->send($user->toArray());
    }

    /**
     * Subscribes a user to a given channel, if the subscription has
     * been done before, it only displays the information of the subscription
     * else, creates the subscription and displays the resulting information
     * @param [int] $iduser    User identification
     * @param [int] $idchannel Channel identification
     */
    public function setSubscribed($iduser, $idchannel)
    {
        try {
            $subscription = User::find($iduser)->subscriptions()->where('channel_id', $idchannel)->first();
            if (!isset($subscription) || empty($subscription)) {
                if (!empty(User::find($iduser)->toArray()) && !empty(Channel::find($idchannel)->toArray())) {
                    $subscription = new Subscription;
                    $subscription->user_id = $iduser;
                    $subscription->channel_id = $idchannel;
                    $subscription->save();
                }
            }
        } catch (QueryException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        } catch (\Exception $e) {
            throw new PushApiException(PushApiException::INVALID_ACTION);
        }
        $this->send($subscription->toArray());
    }

    /**
     * Retrives all subscriptions of a given user or it also can check
     * if user is subscribed into a channel (if he is subscribed, the 
     * subscription is displayed)
     * @param [int] $iduser    User identification
     * @param [int] $idchannel Channel identification
     */
    public function getSubscribed($iduser, $idchannel = false)
    {
        try {
            if ($idchannel) {
                $subscriptions = User::findOrFail($iduser)->subscriptions()->where('channel_id', $idchannel)->first();
            } else {
                $subscriptions = User::findOrFail($iduser)->subscriptions;
            }
            $this->send($subscriptions->toArray());
        } catch (ModelNotFoundException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        } catch (\Exception $e) {
            throw new PushApiException(PushApiException::INVALID_ACTION);
        }
    }

    /**
     * Deletes a user subscription given a user and a subscription id
     * @param [int] $iduser    User identification
     * @param [int] $idchannel Channel identification
     */
    public function deleteSubscribed($iduser, $idchannel)
    {
        try {
            $subscription = User::findOrFail($iduser)->subscriptions()->where('channel_id', $idchannel)->first();
            $subscription->delete();
            $this->send($subscription->toArray());
        } catch (ModelNotFoundException $e) {
            throw new PushApiException(PushApiException::NOT_FOUND);
        } catch (\Exception $e) {
            throw new PushApiException(PushApiException::INVALID_ACTION);
        }
    }
}