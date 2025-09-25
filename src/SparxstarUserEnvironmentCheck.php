<?php
namespace Starisian\SparxstarUEC;
/**
 * Sparxstar User Environment Check
 *
 * This class handles the initialization and setup of the Sparxstar User Environment Check plugin.
 *
 * @package SparxstarUserEnvironmentCheck
 * @version 1.0.0
 * @since 1.0.0
 * 
 */



class SparxstarUserEnvironmentCheck {
  private function __contstructor(){
    $this->register_hooks();

  }

  public function get_instance(): SparxstarUserEnvironmentCheck {

  }

  private function register_hooks(): void {
    /**
    * Add Accept-CH header to the site's front-end to request Client Hints from the browser.
    */
    add_action( 'send_headers', [ $this, 'add_client_hints_header' ]);
  }
  
    private function add_client_hints_header() {
      // Only send on front-end, non-admin pages.
      if ( is_admin() ) {
          return;
      }
    
      header( "Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, Sec-CH-UA-Full-Version, Sec-CH-UA-Platform-Version, Sec-CH-UA-Bitness" );
    }
}
