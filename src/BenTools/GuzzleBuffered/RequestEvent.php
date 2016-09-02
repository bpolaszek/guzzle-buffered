<?php

namespace BenTools\GuzzleBuffered;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\Event;

class RequestEvent extends Event {

    const BEFORE  = 'bentools.guzzle.pool.request.before';
    const DONE    = 'bentools.guzzle.pool.request.done';
    const SUCCESS = 'bentools.guzzle.pool.request.success';
    const ERROR   = 'bentools.guzzle.pool.request.error';

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @var RequestException
     */
    protected $exception;

    /**
     * RequestEvent constructor.
     * @param RequestInterface $request
     */
    public function __construct(RequestInterface $request) {
        $this->request = $request;
    }

    /**
     * @return RequestInterface
     */
    public function getRequest() {
        return $this->request;
    }

    /**
     * @param RequestInterface $request
     * @return $this - Provides Fluent Interface
     */
    public function setRequest($request) {
        $this->request = $request;
        return $this;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse() {
        return $this->response;
    }

    /**
     * @param ResponseInterface $response
     * @return $this - Provides Fluent Interface
     */
    public function setResponse($response) {
        $this->response = $response;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasResponse() {
        return (bool) $this->response;
    }

    /**
     * @return RequestException
     */
    public function getException() {
        return $this->exception;
    }

    /**
     * @param RequestException $exception
     * @return $this - Provides Fluent Interface
     */
    public function setException($exception) {
        $this->exception = $exception;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasException() {
        return (bool) $this->exception;
    }
}