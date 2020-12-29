<?php

namespace Themosis\Core\Exceptions;

use Closure;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Reflector;
use Illuminate\Support\Traits\ReflectsClosures;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\Debug\ExceptionHandler as SymfonyExceptionHandler;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run as Whoops;

class Handler implements ExceptionHandler
{
    use ReflectsClosures;

    /**
     * @var Container
     */
    protected $container;

    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [];

    /**
     * The callbacks that should be used during reporting.
     *
     * @var array
     */
    protected $reportCallbacks = [];

    /**
     * The callbacks that should be used during rendering.
     *
     * @var array
     */
    protected $renderCallbacks = [];

    /**
     * The registered exception mappings.
     *
     * @var array
     */
    protected $exceptionMap = [];

    /**
     * A list of the internal exception types that should not be reported.
     *
     * @var array
     */
    protected $internalDontReport = [
        AuthenticationException::class,
        AuthorizationException::class,
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        SuspiciousOperationException::class,
        TokenMismatchException::class,
        ValidationException::class
    ];

    /**
     * A list of inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation'
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->register();
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register()
    {
        //
    }

    /**
     * Register a reportable callback.
     *
     * @param callable $reportUsing
     *
     * @return \Themosis\Core\Exceptions\ReportableHandler
     */
    public function reportable(callable $reportUsing)
    {
        return tap(new ReportableHandler($reportUsing), function ($callback) {
            $this->reportCallbacks[] = $callback;
        });
    }

    /**
     * Register a renderable callback.
     *
     * @param callable $renderUsing
     *
     * @return $this
     */
    public function renderable(callable $renderUsing)
    {
        $this->renderCallbacks[] = $renderUsing;

        return $this;
    }

    /**
     * Register a new exception mapping.
     *
     * @param \Closure|string      $from
     * @param \Closure|string|null $to
     *
     * @return $this
     */
    public function map($from, $to = null)
    {
        if (is_string($to)) {
            $to = function ($exception) use ($to) {
                return new $to('', 0, $exception);
            };
        }

        if (is_callable($from) && is_null($to)) {
            $from = $this->firstClosureParameterType($to = $from);
        }

        if (! is_string($from) || ! $to instanceof Closure) {
            throw new InvalidArgumentException('Invalid exception mapping.');
        }

        $this->exceptionMap[$from] = $to;

        return $this;
    }

    /**
     * Indicate that the given exception type should not be reported.
     *
     * @param string $class
     *
     * @return $this
     */
    protected function ignore(string $class)
    {
        $this->dontReport[] = $class;

        return $this;
    }

    /**
     * Report or log an exception.
     *
     * @param \Throwable $e
     *
     * @throws \Throwable
     */
    public function report(Throwable $e)
    {
        $e = $this->mapException($e);

        if ($this->shouldntReport($e)) {
            return;
        }

        if (Reflector::isCallable($reportCallable = [$e, 'report'])) {
            if ($this->container->call($reportCallable) !== false) {
                return;
            }
        }

        foreach ($this->reportCallbacks as $reportCallback) {
            if ($reportCallback->handles($e)) {
                if ($reportCallback($e) === false) {
                    return;
                }
            }
        }

        try {
            $logger = $this->container->make(LoggerInterface::class);
        } catch (Exception $exception) {
            throw $e;
        }

        $logger->error(
            $e->getMessage(),
            array_merge(
                $this->exceptionContext($e),
                $this->context(),
                [
                    'exception' => $e
                ]
            )
        );
    }

    /**
     * Map the exception using a registered mapper if possible.
     *
     * @param \Throwable $e
     *
     * @return \Throwable
     */
    protected function mapException(Throwable $e)
    {
        foreach ($this->exceptionMap as $class => $mapper) {
            if (is_a($e, $class)) {
                return $mapper($e);
            }
        }

        return $e;
    }

    /**
     * Get the default exception context variables for logging.
     *
     * @param \Throwable $e
     *
     * @return array
     */
    protected function exceptionContext(Throwable $e)
    {
        return [];
    }

    /**
     * Determine if the exception is in the "do not report" list.
     *
     * @param Throwable $e
     *
     * @return bool
     */
    protected function shouldntReport(Throwable $e)
    {
        $dontReport = array_merge($this->dontReport, $this->internalDontReport);

        return ! is_null(Arr::first($dontReport, function ($type) use ($e) {
            return $e instanceof $type;
        }));
    }

    /**
     * Get the default context variables for logging.
     *
     * @return array
     */
    protected function context()
    {
        try {
            return [
                'userId' => Auth::id(),
                'email' => optional(Auth::user())->email
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Throwable               $e
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        if (method_exists($e, 'render') && $response = $e->render($request)) {
            return Router::toResponse($request, $response);
        } elseif ($e instanceof Responsable) {
            return $e->toResponse($request);
        }

        $e = $this->prepareException($e);

        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        } elseif ($e instanceof AuthenticationException) {
            return $this->unauthenticated($request, $e);
        } elseif ($e instanceof ValidationException) {
            return $this->convertValidationExceptionToResponse($e, $request);
        }

        return $request->expectsJson() ?
            $this->prepareJsonResponse($request, $e) :
            $this->prepareResponse($request, $e);
    }

    /**
     * Prepare exception for rendering.
     *
     * @param Throwable $e
     *
     * @return Exception
     */
    protected function prepareException(Throwable $e)
    {
        if ($e instanceof ModelNotFoundException) {
            $e = new NotFoundHttpException($e->getMessage(), $e);
        } elseif ($e instanceof AuthorizationException) {
            $e = new AccessDeniedHttpException($e->getMessage(), $e);
        } elseif ($e instanceof TokenMismatchException) {
            $e = new HttpException(419, $e->getMessage(), $e);
        }

        return $e;
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param Request                 $request
     * @param AuthenticationException $e
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     * @return Response
     */
    protected function unauthenticated($request, AuthenticationException $e)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], 401)
            : redirect()->guest($e->redirectTo() ?? route('login'));
    }

    /**
     * Create a response instance from the given validation exception.
     *
     * @param ValidationException $e
     * @param Request             $request
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     * @return SymfonyResponse
     */
    protected function convertValidationExceptionToResponse(ValidationException $e, $request)
    {
        if ($e->response) {
            return $e->response;
        }

        return $request->expectsJson()
            ? $this->invalidJson($request, $e)
            : $this->invalid($request, $e);
    }

    /**
     * Convert a validation exception into a JSON response.
     *
     * @param Request             $request
     * @param ValidationException $e
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     * @return JsonResponse
     */
    protected function invalidJson($request, ValidationException $e)
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors()
        ], $e->status);
    }

    /**
     * Convert a validation exception into a response.
     *
     * @param Request             $request
     * @param ValidationException $exception
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function invalid($request, ValidationException $exception)
    {
        return redirect($exception->redirectTo ?? url()->previous())
            ->withInput(Arr::except($request->input(), $this->dontFlash))
            ->withErrors($exception->errors(), $exception->errorBag);
    }

    /**
     * Prepare a JSON response for the given exception.
     *
     * @param $request
     * @param Exception $e
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return JsonResponse
     */
    protected function prepareJsonResponse($request, Throwable $e)
    {
        $status = $this->isHttpException($e) ? $e->getStatusCode() : 500;
        $headers = $this->isHttpException($e) ? $e->getHeaders() : [];

        return new JsonResponse(
            $this->convertExceptionToArray($e),
            $status,
            $headers,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Determine if the given exception is an HTTP exception.
     *
     * @param Exception $e
     *
     * @return bool
     */
    protected function isHttpException(Exception $e)
    {
        return $e instanceof HttpException;
    }

    /**
     * Convert the given exception to an array.
     *
     * @param Exception $e
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return array
     */
    protected function convertExceptionToArray(Exception $e)
    {
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(function ($trace) {
                return Arr::except($trace, ['args']);
            })->all()
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error'
        ];
    }

    /**
     * Prepare a response for the given exception.
     *
     * @param \Illuminate\Http\Request $request
     * @param Exception                $e
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function prepareResponse($request, Exception $e)
    {
        if (! $this->isHttpException($e) && config('app.debug')) {
            return $this->toIlluminateResponse(
                $this->convertExceptionToResponse($e),
                $e
            );
        }

        if (! $this->isHttpException($e)) {
            $e = new HttpException(500, $e->getMessage());
        }

        return $this->toIlluminateResponse(
            $this->renderHttpException($e),
            $e
        );
    }

    /**
     * Map the given exception into an Illuminate response.
     *
     * @param \Symfony\Component\HttpFoundation\Response $response
     * @param Exception                                  $e
     *
     * @return \Illuminate\Http\Response
     */
    protected function toIlluminateResponse($response, Exception $e)
    {
        if ($response instanceof SymfonyRedirectResponse) {
            $response = new SymfonyRedirectResponse(
                $response->getTargetUrl(),
                $response->getStatusCode(),
                $response->headers->all()
            );
        } else {
            $response = new Response(
                $response->getContent(),
                $response->getStatusCode(),
                $response->headers->all()
            );
        }

        return $response->withException($e);
    }

    /**
     * Convert an exception to a response instance.
     *
     * @param Exception $e
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function convertExceptionToResponse(Exception $e)
    {
        $headers = $this->isHttpException($e) ? $e->getHeaders() : [];
        $statusCode = $this->isHttpException($e) ? $e->getStatusCode() : 500;

        try {
            $content = config('app.debug') && class_exists(Whoops::class)
                ? $this->renderExceptionWithWhoops($e)
                : $this->renderExceptionWithSymfony($e, config('app.debug'));
        } catch (Exception $e) {
            $content = $content ?? $this->renderExceptionWithSymfony($e, config('app.debug'));
        }

        return SymfonyResponse::create(
            $content,
            $statusCode,
            $headers
        );
    }

    /**
     * Render an exception using Whoops.
     *
     * @param Exception $e
     *
     * @return string
     */
    protected function renderExceptionWithWhoops(Exception $e)
    {
        return tap(new Whoops(), function ($whoops) {
            $whoops->pushHandler($this->whoopsHandler());
            $whoops->writeToOutput(false);
            $whoops->allowQuit(false);
        })->handleException($e);
    }

    /**
     * Get the Whoops handler for the application.
     *
     * @return \Whoops\Handler\Handler
     */
    protected function whoopsHandler()
    {
        return tap(new PrettyPageHandler(), function ($handler) {
            $files = new Filesystem();
            $handler->handleUnconditionally(true);

            foreach (config('app.debug_blacklist', []) as $key => $secrets) {
                foreach ($secrets as $secret) {
                    $handler->blacklist($key, $secret);
                }
            }

            if (config('app.editor', false)) {
                $handler->setEditor(config('app.editor'));
            }

            $handler->setApplicationPaths(
                array_flip(Arr::except(
                    array_flip($files->directories(base_path())),
                    [base_path('vendor')]
                ))
            );
        });
    }

    /**
     * Render an exception to a string using Symfony.
     *
     * @param Exception $e
     * @param $debug
     *
     * @return string
     */
    protected function renderExceptionWithSymfony(Exception $e, $debug)
    {
        return (new SymfonyExceptionHandler($debug))->getHtml(FlattenException::create($e));
    }

    /**
     * Render the given HttpException.
     *
     * @param \Symfony\Component\HttpKernel\Exception\HttpException $e
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return \Symfony\Component\HttpFoundation\Response;
     */
    protected function renderHttpException(HttpException $e)
    {
        $status = $e->getStatusCode();

        $paths = collect(view()->getFinder()->getPaths());

        view()->replaceNamespace('errors', $paths->map(function ($path) {
            return "{$path}/errors";
        })->push(__DIR__.'/views')->all());

        if (view()->exists($view = "errors::{$status}")) {
            return response()->view($view, [
                'exception' => $e
            ], $status, $e->getHeaders());
        }

        return $this->convertExceptionToResponse($e);
    }

    /**
     * Render an exception to the console.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param \Throwable                                        $e
     */
    public function renderForConsole($output, Throwable $e)
    {
        (new ConsoleApplication())->renderThrowable($e, $output);
    }

    /**
     * Determine if the exception handler should be reported.
     *
     * @param Throwable $e
     *
     * @return bool
     */
    public function shouldReport(Throwable $e)
    {
        return ! $this->shouldntReport($e);
    }
}
