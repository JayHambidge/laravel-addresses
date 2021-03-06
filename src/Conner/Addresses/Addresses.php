<?php namespace Conner\Addresses;

use Illuminate\Database\Eloquent\Collection;
use Cache;
use Conner\Addresses\NotLoggedInException;

/**
 * Primary handler for managing addresses
 */
class Addresses {
	
	private static $userId = null;
	
	/**
	 * Create a new address using post array data
	 *
	 * @param array $data
	 * @return object $address or null
	 */
	public function createAddress($data = null) {
		if(is_null($data)) {
			$data = \Input::all();
		}

		$address = new Address($data);
		
		$address->user_id = self::userId();
		if($address->save()) {
			self::checkFlags($address);
			return $address;
		}
	}
	
	/**
	 * Create a new address using post array data
	 *
	 * @param object or id $address
	 * @param array $data
	 * @return object $address or null
	 */
	public function updateAddress($address, $data = null) {
		if(is_null($data)) {
			$data = \Input::all();
		}
	
		if(!is_object($address)) {
			$address = Address::where('user_id', self::userId())
				->where('id', $address)
				->first();
		}
		
		if(empty($address)) {
			throw new InvalidOperationException;
		}
		
		$address->fill($data);
		$flags = \Config::get('addresses.flags');
		foreach($flags as $flag) {
			if(array_key_exists('is_'.$flag, $data)) {
				$address->{'is_'.$flag} = $data['is_'.$flag];
			}
		}
		$address->save();
		return $address;
	}

	/**
	 * Delete address. Will delete it if it can. This function does a check to make sure logged in
	 * user owns the address
	 *
	 * @param object or id $address
	 */
	public function deleteAddress($address) {
		$userId = self::userId();
		
		if(!is_object($address)) {
			$address = Address::where('user_id', $userId)
			->where('id', $address)
			->first();
		}

		if($address->user_id == $userId) {
			$address->delete();
		}
	}
	
	/**
	 * Return instance of Illuminate\Validation\Validator that is setup with Address rules and data (from html input)
	 * Addresses::getValidator()->fails(); // test input from user
	 *
	 * @param array $input input array from user (or null to default to Input::all())
	 * @return Illuminate\Validation\Validator ready to test for fails|passes
	 */
	function getValidator($input = null) {
		$rules = Address::rules();
		
		if(is_null($input)) {
			$input = \Input::all();
		}
		
		$address = new Address($input);
		
		return \Validator::make($address->toArray(), $rules);
	}
	
	/**
	 * Return Collection of Addresses owned by the given userID.
	 *
	 * @param Collection
	 */
	public function getAll($userId=null) {
		if($userId = $userId ?: self::userId(false)) {
	
			$builder = Address::where('user_id', $userId);
			
			$flags = \Config::get('addresses.flags');
			foreach($flags as $flag) {
				$builder->orderBy('is_'.$flag, 'DESC');
			}
	
			return $builder->orderBy('id', 'ASC')
				->get();
		}
	}

	/**
	 * Return Collection of Addresses owned by the given userID.
	 *
	 * @param Collection
	 */
	public function getList($userId=null) {
		$list = array();
		if($addresses = self::getAll($userId)) {
			foreach($addresses as $address) {
				$list[$address->id] = $address->display;
			}
		}
		return $list;
	}
	
	public function __call($name, $arguments) {
		$flags = \Config::get('addresses.flags');

		foreach($flags as $flag) {
			if($name == 'get'.ucfirst($flag)) {
				array_unshift($arguments, $flag);
				return call_user_func_array('self::getFlag', $arguments);
			}
			
			if($name == 'set'.ucfirst($flag)) {
				array_unshift($arguments, $flag);
				return call_user_func_array('self::setFlag', $arguments);
			}
		}
		
		$class = get_class($this);
		$trace = debug_backtrace();
		$file = $trace[0]['file'];
		$line = $trace[0]['line'];
		trigger_error("Call to undefined method $class::$name()", E_USER_ERROR);
	}
	
	/**
	 * Get the bool value of a flag. Called by using Addresses::getFlagname($userId)
	 *
	 * @param $userId or null
	 * @return Address
	 */
	private function getFlag($flag, $userId=null) {
		$userId = $userId ?: self::userId();

		if(empty($userId)) {
            return null;
        }
		
		return Address::where('user_id', '=', $userId)
			->where('is_'.$flag, true)
			->first();
	}

	
	/**
	 * Return collection of all countries
	 *
	 * @return Collection
	 */
	public static function getCountries() {
		return Cache::rememberForever('addresses.countries', function() {
			return Country::orderBy('name', 'ASC')->get();
		});
	}

	/**
	 * Return collection of all states/provinces within a country
	 * TODO: caching to make this fetch speedy speedy
	 *
	 * @param string 2 letter country alpha-code
	 * @return Collection
	 */
	public static function getStates($countryA2 = 'US') {
		if(strlen($countryA2) != 2) {
			throw new InvalidValueException;
		}
		
		return Cache::rememberForever('addresses.'.$countryA2.'.states', function() use ($countryA2) {
			return State::where('country_a2', $countryA2)->orderBy('name', 'ASC')->get();
		});
	}
	
	/**
	 * Accept 2 or 3 digit alpha-code
	 *
	 * @param string $countryA2
	 * @return $string full country name
	 */
	public static function countryName($countryA2) {
		if(strlen($countryA2) != 2) {
			throw new InvalidValueException;
		}

		return Cache::rememberForever('addresses.'.$countryA2.'.country_name', function() use ($countryA2) {
			return Country::byCode($countryA2)->first()->name;
		});
	}

	/**
	 * Accept 2 digit alpha-code. Pass in the country to be extra sure you get the right name returned.
	 * TODO: caching to make this fetch speedy speedy
	 *
	 * @param string $stateA2
	 * @param string $countryA2 defaults to 'US'
	 * @return $string full state/province name
	 */
	public static function stateName($stateA2, $countryA2 = 'US') {
		if(strlen($stateA2) != 2 || strlen($countryA2) != 2) {
			throw new InvalidValueException;
		}
		
		if(empty($countryA2)) {
			return State::byCode($code)->firstOrFail()->name;
		}

		return Cache::rememberForever('addresses.'.$countryA2.'.'.$stateA2.'.state_name', function() use ($stateA2, $countryA2) {
			return State::byCountry($countryA2)->byCode($stateA2)->firstOrFail()->name;
		});
	}

	/**
	 * Wrapper for \Form::select that populated the country list automatically
	 * Defaults to United States as selected
	 *
	 * @param string $name
	 * @param string $selected
	 * @param array $options
	 */
	public function selectCountry($name, $selected = 'US', $options = array()) {
		$list = array();
		foreach (self::getCountries() as $country) {
			if($country->a2 == 'US') {
				$usa = $country;
			} else {
				$list[$country->a2] = $country->name;
			}
		}
		
		$list = array_merge(array('US'=>$usa->name), $list);

		return \Form::select($name, $list, $selected, $options);
	}
	
	/**
	 * Wrapper for \Form::select that populated the state/province list automatically
	 * Defaults to United States as selected
	 *
	 * @param string $name
	 * @param string $selected
	 * @param array $options
	 *   $options['country'] = 'US'
	 */
	public function selectState($name, $selected = null, $options = array('country'=>'US')) {
		$list = array(''=>'');
		
		foreach (self::getStates($options['country']) as $state) {
			$list[$state->a2] = $state->name;
		}
		
		unset($options['country']);

		return \Form::select($name, $list, $selected, $options);
	}
	
	private function checkFlags(Address $address) {
		$flags = \Config::get('addresses.flags');
		foreach($flags as $flag) {
			if($address->{'is_'.$flag} === 1) {
				self::setFlag($flag, $address);
			}
		}
	}

    /**
     * Set value of a flag. Unsets all other addresses for that user.
     * Called by using Addresses::setFlagname($address)
     *
     * @param mixed $objectOrId primary address id or object instance
     */
    private function setFlag($flag,$address) {
        if(is_int($address)) {
            $address = Address::find($address);
        }

        //Set Other Same Flags To 0
        Address::where('user_id', '=', self::userId())
            ->update(['is_'.$flag => 0]);

        //Set the new flag to 1
        $address->{'is_'.$flag} = 1;
        $address->save();
    }
	
	private function userId($required = true) {
		if(self::$userId) {
			return self::$userId;
		}
		
		if($user = call_user_func(\Config::get('addresses.user.current'))) {
			self::$userId = $user->id;
			return self::$userId;
		}

		if($required) {
			throw new NotLoggedInException;
		}
		return null;
	}
	
}