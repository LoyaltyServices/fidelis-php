<?php namespace LoyaltyServices\Fidelis;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class FidelisServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		$this->package('loyalty-services/fidelis');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app['fidelis'] = $this->app->share(function ($app)
		{
			$fidelis = new Fidelis(Config::get('fidelis::WCF'), Config::get('fidelis::virtualTerminalId'));

			// return pusher
			return $fidelis;
		});

		// Shortcut so developers don't need to add an Alias in app/config/app.php
		$this->app->booting(function ()
		{
			$loader = AliasLoader::getInstance();
			$loader->alias('Fidelis', 'LoyaltyServices\Fidelis\Facades\Fidelis');
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array();
	}

}
