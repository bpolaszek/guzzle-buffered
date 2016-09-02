<?php

namespace BenTools\GuzzleBuffered;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @method ResponseInterface get($uri, array $options = [])
 * @method ResponseInterface head($uri, array $options = [])
 * @method ResponseInterface put($uri, array $options = [])
 * @method ResponseInterface post($uri, array $options = [])
 * @method ResponseInterface patch($uri, array $options = [])
 * @method ResponseInterface delete($uri, array $options = [])
 * @method PromiseInterface getAsync($uri, array $options = [])
 * @method PromiseInterface headAsync($uri, array $options = [])
 * @method PromiseInterface putAsync($uri, array $options = [])
 * @method PromiseInterface postAsync($uri, array $options = [])
 * @method PromiseInterface patchAsync($uri, array $options = [])
 * @method PromiseInterface deleteAsync($uri, array $options = [])
 */
class Client implements ClientInterface {

    /**
     * @var Guzzle
     */
    private $decoratedClient;

    /**
     * @var array
     */
    private $requestStorage;

    /**
     * @var array
     */
    private $promiseStorage;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var int
     */
    private $concurrency;

    /**
     * Client constructor.
     * @param Guzzle                   $decoratedClient
     * @param int                      $concurrency
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(Guzzle $decoratedClient, $concurrency = 10, EventDispatcherInterface $eventDispatcher = null) {
        $this->decoratedClient = $decoratedClient;
        $this->concurrency     = $concurrency;
        $this->eventDispatcher = $eventDispatcher instanceof EventDispatcherInterface ? $eventDispatcher : new EventDispatcher;
    }

    /**
     * @inheritDoc
     */
    public function send(RequestInterface $request, array $options = []) {
        return $this->decoratedClient->send($request, $options);
    }

    /**
     * @param RequestInterface $request
     * @return Promise
     */
    public function sendAsync(RequestInterface $request, array $options = []) {
        $index                        = uniqid();
        $this->requestStorage[$index] = $request;
        $this->promiseStorage[$index] = $promise = new Promise(function () use (&$promise, $request, $options, $index) {

            $event   = $this->eventDispatcher->dispatch(RequestEvent::BEFORE, new RequestEvent($request));
            $request = $event->getRequest();

            $promise->then(function ($response) use ($index) {

                $event = new RequestEvent($this->requestStorage[$index]);
                $event->setResponse($response);
                $event = $this->eventDispatcher->dispatch(RequestEvent::DONE, $event);
                $event = $this->eventDispatcher->dispatch(RequestEvent::SUCCESS, $event);

                unset($this->promiseStorage[$index]);
                unset($this->requestStorage[$index]);
                return $event->getResponse();

            })->otherwise(function ($reason) use ($index) {

                $event = new RequestEvent($this->requestStorage[$index]);
                $event->setException($reason);
                $event = $this->eventDispatcher->dispatch(RequestEvent::DONE, $event);
                $event = $this->eventDispatcher->dispatch(RequestEvent::ERROR, $event);

                unset($this->promiseStorage[$index]);
                unset($this->requestStorage[$index]);
                return $event->getException();

            });

            if ($event->hasResponse()) {
                $promise->resolve($event->getResponse());
            }
            elseif ($event->hasException()) {
                $promise->reject($event->getException());
            }

            else {
                try {
                    $response = $this->getDecoratedClient()->send($request, $options);
                    $promise->resolve($response);
                }
                catch (RequestException $exception) {
                    $promise->reject($exception);
                }
            }


        });
        return $promise;
    }

    /**
     * Flush waiting requests
     */
    public function flush() {

        $requests = function () {

            foreach ($this->requestStorage AS $index => $request) {

                $event   = $this->eventDispatcher->dispatch(RequestEvent::BEFORE, new RequestEvent($request));
                $request = $event->getRequest();

                if ($event->hasResponse()) {
                    $this->promiseStorage[$index]->resolve($event->getResponse());
                    unset($this->promiseStorage[$index]);
                    unset($this->requestStorage[$index]);
                }
                elseif ($event->hasException()) {
                    $this->promiseStorage[$index]->reject($event->getException());
                    unset($this->promiseStorage[$index]);
                    unset($this->requestStorage[$index]);
                }
                else {
                    yield $index => $request;
                }
            }
        };

        $pool = new Pool($this->getDecoratedClient(), $requests(), [
            'concurrency' => (int) $this->concurrency,
            'fulfilled'   => function (\Psr\Http\Message\ResponseInterface $response, $index) {
                $event = new RequestEvent($this->requestStorage[$index]);
                $event->setResponse($response);
                $event = $this->eventDispatcher->dispatch(RequestEvent::DONE, $event);
                $event = $this->eventDispatcher->dispatch(RequestEvent::SUCCESS, $event);
                $this->promiseStorage[$index]->resolve($event->getResponse());
                unset($this->promiseStorage[$index]);
                unset($this->requestStorage[$index]);
            },
            'rejected'    => function ($reason, $index) {
                $event = new RequestEvent($this->requestStorage[$index]);
                $event->setException($reason);
                $event = $this->eventDispatcher->dispatch(RequestEvent::DONE, $event);
                $event = $this->eventDispatcher->dispatch(RequestEvent::ERROR, $event);
                $this->promiseStorage[$index]->reject($event->getException());
                unset($this->promiseStorage[$index]);
                unset($this->requestStorage[$index]);
            },
        ]);

        // Initiate the transfers and create a promise
        $promise = $pool->promise();

        // Force the pool of requests to complete.
        $promise->then(function () {
            if (count($this->requestStorage) > 0) {
                $this->flush();
            }
        })->wait();
    }

    /**
     * @inheritDoc
     */
    public function request($method, $uri = null, array $options = []) {
        return $this->decoratedClient->request($method, $uri, $options);
    }

    /**
     * @inheritDoc
     */
    public function requestAsync($method, $uri, array $options = []) {
        return $this->decoratedClient->requestAsync($method, $uri, $options);
    }

    /**
     * @inheritDoc
     */
    public function getConfig($option = null) {
        return $this->decoratedClient->getConfig($option);
    }

    public function __call($method, $args) {
        return call_user_func_array([$this->decoratedClient, $method], $args);
    }

    /**
     * @return Guzzle
     */
    public function getDecoratedClient() {
        return $this->decoratedClient;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher() {
        return $this->eventDispatcher;
    }

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @return $this - Provides Fluent Interface
     */
    public function setEventDispatcher($eventDispatcher) {
        $this->eventDispatcher = $eventDispatcher;
        return $this;
    }

}