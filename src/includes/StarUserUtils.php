<?php
/**
 *
 */
// If this file is called directly, abort.
if ((!defined('ABSPATH')) || (!defined('WPINC'))) {
	exit;
}

class StarUserUtils{
	public static function star_getIP(){
			return $_SERVER['REMOTE_ADDR'];
	}

	public static function star_getUserIP(){
		// PHP7+
    $clientIP = $_SERVER['HTTP_CLIENT_IP'] 
        ?? $_SERVER["HTTP_CF_CONNECTING_IP"] # when behind cloudflare
        ?? $_SERVER['HTTP_X_FORWARDED'] 
        ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['HTTP_FORWARDED'] 
        ?? $_SERVER['HTTP_FORWARDED_FOR'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? '0.0.0.0';
    
    // Earlier than PHP7
    $clientIP = '0.0.0.0';
    
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $clientIP = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // when behind cloudflare
        $clientIP = $_SERVER['HTTP_CF_CONNECTING_IP']; 
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
        $clientIP = $_SERVER['HTTP_X_FORWARDED'];
    } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
        $clientIP = $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
        $clientIP = $_SERVER['HTTP_FORWARDED'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $clientIP = $_SERVER['REMOTE_ADDR'];
    }
    return $clientIP;
	}

	public static function star_getUserSessionID(){
		return session_id();
	}

	public static function star_getUserAgent(): string{
		return $_SERVER['HTTP_USER_AGENT'];
	}

	public static function star_getCurrentURL(): string{
		return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
	}

	public static function star_getReferrerURL(): string{
		return isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
	}

	public static function star_getIPGeoLocation(): string{
		$ip = self::star_getUserIP();
    // TODO Replace 'YOUR_ACCESS_TOKEN' with your actual API token from a service like ipinfo.io
    $token = 'YOUR_ACCESS_TOKEN'; 
    $url = "https://api.ipinfo.io/lite/$ip?token=$token";

    $geolocationData = file_get_contents($url);
    return json_decode($geolocationData, true); // Decode JSON response into an associative array
}

public static function star_getGeoLocationData(): string {
  $userIp = getUserIP();
  $locationData = getUserGeolocation($userIp);

  if (is_array($locationData) && ! is_array_empty($locationData)) {
    switch $data
      case "city":
          return trim($locationData['city']);
      case "region":
          return trim($locationData['region']);
      case "country":
          return trim($locationData['country']);
      case "location":
          return trim($locationData['loc']);
      default:
  } else {
      return "Location data unavailable.";
  }
}

  public static function star_getUserLanguage(string $retType='code'): string{
    // Get the raw Accept-Language header
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    
    // Extract the primary language code (e.g., "en-US" or "en")
    // This example takes the first part before a comma and before a semicolon
    $primaryLanguage = explode(',', $acceptLanguage)[0];
    $primaryLanguage = explode(';', $primaryLanguage)[0];
    
    // You might want to further process this to get just the two-letter code
    $twoLetterCode = substr($primaryLanguage, 0, 2);

    if($retType === 'code'){
      // returns two letter code e.g. "en"
      return trim($twoLetterCode);
    } else {
      // returns locale "en_US"
      return trim($primaryLanguage);
    }
  }

	public static function star_getUserDeviceType(){
		$user_agent = $_SERVER['HTTP_USER_AGENT'];
		if (preg_match('/mobile/i', $user_agent)) {
			return 'Mobile';
		} elseif (preg_match('/tablet/i', $user_agent)) {
			return 'Tablet';
		} else {
			return 'Desktop';
		}
	}

  public static function star_getUserAgent(): string{
    return $_SERVER['HTTP_USER_AGENT'];
  }

	public static function star_getUserOS(){
		$user_agent = $_SERVER['HTTP_USER_AGENT'];
		if (preg_match('/win/i', $user_agent)) {
			return 'Windows';
		} elseif (preg_match('/mac/i', $user_agent)) {
			return 'Mac';
		} elseif (preg_match('/linux/i', $user_agent)) {
			return 'Linux';
		} elseif (preg_match('/unix/i', $user_agent)) {
			return 'Unix';
		} else {
			return 'Other';
		}
	}


}
