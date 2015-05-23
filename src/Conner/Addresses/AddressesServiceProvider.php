<?php namespace Conner\Addresses;

use Illuminate\Support\ServiceProvider;

class AddressesServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 */
// 	protected $defer = false;
	
	/**
	 * Bootstrap the application events.
	 */
	public function boot() {
        $configPath     = base_path('vendor/rtconner/laravel-addresses/src/config/config.php');
        $viewsPath      = base_path('vendor/rtconner/laravel-addresses/src/views');
        $migrationsPath = base_path('vendor/rtconner/laravel-addresses/src/migrations');
        $seedsPath      = base_path('vendor/rtconner/laravel-addresses/src/migrations/seeds');

        $this->publishes([$migrationsPath => database_path().'/migrations']);
        $this->publishes([$seedsPath      => database_path().'/seeds']);
        $this->publishes([$configPath     => config_path('addresses.php')], 'config');
        \View::addNamespace('addresses',$viewsPath);
        $this->loadViewsFrom($viewsPath, 'Addresses');
	}
	
	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {
		
		$this->app['addresses'] = $this->app->share(function($app) {
			return new Addresses();
		});
		
		$this->app->booting(function() {

			$loader = \Illuminate\Foundation\AliasLoader::getInstance();
			$loader->alias('Addresses', 'Conner\Addresses\AddressesFacade');
		
		});

	}

	public function provides() {
		return array('addresses');
	}
	
}