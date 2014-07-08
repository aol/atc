<?php

namespace Aol\Atc;

use Aol\Atc\Action\ServerError;
use Aol\Atc\Exceptions\ActionNotFoundException;
use Aol\Atc\Exceptions\ExitDispatchException;
use Aol\Atc\Exceptions\PageNotFoundException;
use Aura\Router\Router;
use Aura\Web\Request;
use Aura\Web\Response;
use Aura\Web\WebFactory;
use Psr\Log\LoggerInterface;

abstract class Dispatch
{
	/** @var \Aol\Atc\ActionFactory */
	private $action_factory;

	/** @var \Psr\Log\LoggerInterface */
	private $logger;

	/** @var \Aol\Atc\PresenterInterface */
	private $presenter;

	/** @var \Aura\Router\Router */
	private $router;

	/** @var \Aura\Web\WebFactory */
	private $web_factory;

	/** @var bool */
	private $debug_enabled = false;

	public function __construct(
		Router $router,
		WebFactory $web_factory,
		ActionFactory $action_factory,
		PresenterInterface $presenter,
		LoggerInterface $logger
	) {
		$this->router         = $router;
		$this->web_factory    = $web_factory;
		$this->action_factory = $action_factory;
		$this->presenter      = $presenter;
		$this->logger         = $logger;

		$this->defineRoutes($this->router);
	}

	public function run()
	{
		$view     = null;
		$request  = $this->web_factory->newRequest();
		$response = $this->web_factory->newResponse();

		try {
			// Get the matched route.
			$route = $this->router->getMatchedRoute();
			if (!$route) {
				$this->routeNotMatched($request);
			}

			$params = $route->params;

			// Get the appropriate action.
			$action = $this->action_factory->newInstance($params['action'], $request, $params);
			if (!$action) {
				$this->actionNotFound($params['action']);
			}

			// Run the response through the action.
			$response = $action($response);
		} catch (\Exception $e) {
			$this->debug('Caught ' . get_class($e) . ': ' . $e->getMessage());

			// This is your escape hatch.
			if ($e instanceof ExitDispatchException) {
				throw $e;
			} // Exceptions can implement the action interface to handle themselves.
			elseif ($e instanceof ActionInterface) {
				$action = $e;
			} // When all else fails get the default server error action.
			else {
				$action = $this->serverErrorAction();
			}

			$response = $action($response);
		}

		// Make sure this is not a redirect and then run the response through the presenter.
		if ($response->status->getCode() < 300 || $response->status->getCode > 399) {
			$response = $this->presenter->run($response, $action->getAllowedFormats(), $action->getView());
		}

		$this->send($response);
	}

	public function enableDebug()
	{
		$this->debug_enabled = true;
	}

	public function disableDebug()
	{
		$this->debug_enabled = false;
	}

	/**
	 * Allows child instances to define routes.
	 *
	 * @param Router $router
	 */
	abstract protected function defineRoutes(Router $router);

	/**
	 * Returns the default ServerError action. This is placed in a protected
	 * method so that children can override this behavior as needed.
	 *
	 * @return ServerError
	 */
	protected function serverErrorAction()
	{
		$this->debug('Using the default ServerError action.');

		return new ServerError();
	}

	/**
	 * Logs the requested action and throws an ActionNotFoundException. This is
	 * placed in a protected method so that children can override this behavior
	 * as needed.
	 *
	 * @param string $action Action name
	 * @throws Exceptions\ActionNotFoundException
	 */
	protected function actionNotFound($action)
	{
		$this->debug('Action not found: ' . $action);
		throw new ActionNotFoundException;
	}

	/**
	 * Logs the requested page and throws a PageNotFoundException. This is
	 * placed in a protected method so that children can override this behavior
	 * as needed.
	 *
	 * @param Request $request
	 * @throws Exceptions\PageNotFoundException
	 */
	protected function routeNotMatched(Request $request)
	{
		$this->debug('No matching route: ' . $request->url->get());
		throw new PageNotFoundException;
	}

	/**
	 * Sends a debug message to the logger.
	 *
	 * @param string $message Debug message
	 */
	private function debug($message)
	{
		if ($this->debug_enabled == true) {
			$this->logger->debug($message);
		}
	}

	/**
	 * Sends the response to the browser.
	 *
	 * @see https://github.com/auraphp/Aura.Web/blob/develop-2/README-RESPONSE.md#sending-the-response
	 *
	 * @param Response $response
	 */
	private function send(Response $response)
	{
		// send status line
		header($response->status->get(), true, $response->status->getCode());

		// send non-cookie headers
		foreach ($response->headers->get() as $label => $value) {
			header("{$label}: {$value}");
		}

		// send cookies
		foreach ($response->cookies->get() as $name => $cookie) {
			setcookie(
				$name,
				$cookie['value'],
				$cookie['expire'],
				$cookie['path'],
				$cookie['domain'],
				$cookie['secure'],
				$cookie['httponly']
			);
		}

		// send content
		echo $response->content->get();
	}
}
