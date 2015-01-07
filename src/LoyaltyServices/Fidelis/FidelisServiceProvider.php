<?php namespace LoyaltyServices\Fidelis;

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
		$this->app->bindShared('\LoyaltyServices\Fidelis\Fidelis', function ($app) {
			return new Fidelis($app['config']->get('services.fidelis.wcf'), $app['config']->get('services.fidelis.terminal_id'));
		});
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{

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
