<?php

namespace Sammyjo20\Saloon\Http;

use ReflectionClass;
use ReflectionException;
use Sammyjo20\Saloon\Enums\Method;
use Sammyjo20\Saloon\Clients\MockClient;
use Sammyjo20\Saloon\Data\RequestBodyType;
use Sammyjo20\Saloon\Helpers\ArrayBodyRepository;
use Sammyjo20\Saloon\Helpers\PluginHelper;
use Sammyjo20\Saloon\Interfaces\Data\BodyRepository;
use Sammyjo20\Saloon\Interfaces\Data\WithBody;
use Sammyjo20\Saloon\Interfaces\SenderInterface;
use Sammyjo20\Saloon\Exceptions\DataBagException;
use Sammyjo20\Saloon\Traits\HasRequestProperties;
use Sammyjo20\Saloon\Interfaces\Data\SendsXMLBody;
use Sammyjo20\Saloon\Traits\AuthenticatesRequests;
use Sammyjo20\Saloon\Http\Responses\SaloonResponse;
use Sammyjo20\Saloon\Interfaces\Data\SendsJsonBody;
use Sammyjo20\Saloon\Http\Middleware\MockMiddleware;
use Sammyjo20\Saloon\Interfaces\Data\SendsMixedBody;
use Sammyjo20\Saloon\Interfaces\Data\SendsFormParams;
use Sammyjo20\Saloon\Interfaces\Data\SendsStringBody;
use Sammyjo20\Saloon\Interfaces\AuthenticatorInterface;
use Sammyjo20\Saloon\Interfaces\Data\SendsMultipartBody;
use Sammyjo20\Saloon\Exceptions\PendingSaloonRequestException;
use Sammyjo20\Saloon\Exceptions\SaloonInvalidConnectorException;
use Sammyjo20\Saloon\Exceptions\SaloonInvalidResponseClassException;

class PendingSaloonRequest
{
    use HasRequestProperties;
    use AuthenticatesRequests;

    /**
     * The original request class making the request.
     *
     * @var SaloonRequest
     */
    protected SaloonRequest $request;

    /**
     * The original connector making the request.
     *
     * @var SaloonConnector
     */
    protected SaloonConnector $connector;

    /**
     * The URL the request will be made to.
     *
     * @var string
     */
    protected string $url;

    /**
     * The method the request will use.
     *
     * @var Method
     */
    protected Method $method;

    /**
     * The response class used to create a response.
     *
     * @var string
     */
    protected string $responseClass;

    /**
     * The mock client if provided on the connector or request.
     *
     * @var MockClient|null
     */
    protected ?MockClient $mockClient = null;

    /**
     * The body type.
     *
     * @var RequestBodyType|null
     */
    protected ?RequestBodyType $bodyType = null;

    /**
     * The body repository
     *
     * @var BodyRepository|null
     */
    protected ?BodyRepository $body = null;

    /**
     * The Mock Response Found
     *
     * @var MockResponse|null
     */
    protected ?MockResponse $mockResponse = null;

    /**
     * Build up the request payload.
     *
     * @param SaloonRequest $request
     * @param MockClient|null $mockClient
     * @throws PendingSaloonRequestException
     * @throws ReflectionException
     * @throws DataBagException
     * @throws SaloonInvalidConnectorException
     * @throws SaloonInvalidResponseClassException
     */
    public function __construct(SaloonRequest $request, MockClient $mockClient = null)
    {
        $connector = $request->connector();

        $this->request = $request;
        $this->connector = $connector;
        $this->url = $request->getRequestUrl();
        $this->method = Method::upperFrom($request->getMethod());
        $this->responseClass = $request->getResponseClass();
        $this->mockClient = $mockClient ?? ($request->getMockClient() ?? $connector->getMockClient());
        $this->authenticator = $this->request->getAuthenticator() ?? $this->connector->getAuthenticator();

        // Let's build the PendingSaloonRequest. Since it is made up of many
        // properties, we run an individual method for each one.

        $this
            ->registerDefaultMiddleware()
            ->mergeRequestProperties()
            ->mergeBody()
            ->bootConnectorAndRequest()
            ->bootPlugins()
            ->authenticateRequest();

        // Next, we will execute the request middleware pipeline which will
        // process any middleware added on the connector or the request.

        $this->executeRequestPipeline();
    }

    /**
     * Merge all the properties together.
     *
     * @return $this
     */
    protected function mergeRequestProperties(): static
    {
        $connector = $this->connector;
        $request = $this->request;

        $this->headers()->merge($connector->headers()->all(), $request->headers()->all());
        $this->queryParameters()->merge($connector->queryParameters()->all(), $request->queryParameters()->all());
        $this->config()->merge($connector->config()->all(), $request->config()->all());

        // Merge together the middleware pipelines...

        $this->middleware()
            ->merge($connector->middleware())
            ->merge($request->middleware());

        return $this;
    }

    /**
     * Merge the body together
     *
     * @return $this
     * @throws PendingSaloonRequestException
     */
    protected function mergeBody(): static
    {
        $connector = $this->connector;
        $request = $this->request;

        $connectorBodyType = null;
        $connectorBody = null;

        $requestBodyType = null;
        $requestBody = null;

        if ($connector instanceof WithBody) {
            $connectorBodyType = $connector->getBodyType();
            $connectorBody = $connector->body();
        }

        if ($request instanceof WithBody) {
            $requestBodyType = $request->getBodyType();
            $requestBody = $request->body();
        }

        if (is_null($connectorBodyType) && is_null($requestBodyType)) {
            return $this;
        }

        if (isset($connectorBodyType, $requestBodyType) && $connectorBodyType !== $requestBodyType) {
            throw new PendingSaloonRequestException('Request body type and connector body type cannot be mixed.');
        }

        // The primary data type will be the request data type, if one has not
        // been set, we will use the connector data.

        $this->bodyType = $requestBodyType ?? $connectorBodyType;

        // If both connector and request body types are ArrayBodyRepository then we will
        // merge them together

        if ($connectorBody instanceof ArrayBodyRepository && $requestBody instanceof ArrayBodyRepository) {
            $repository = new ArrayBodyRepository([]);
            $this->body = $repository->merge($connectorBody->all(), $requestBody->all());

            return $this;
        }

        // Otherwise we'll prefer the request body over the connector body.
        // Todo: Tidy up the below?

        if ($connectorBody instanceof BodyRepository) {
            $this->body = $connectorBody;
        }

        if ($requestBody instanceof BodyRepository) {
            $this->body = $requestBody;
        }

        return $this;
    }

    /**
     * Authenticate the request.
     *
     * @return $this
     */
    protected function authenticateRequest(): static
    {
        $authenticator = $this->getAuthenticator();

        if ($authenticator instanceof AuthenticatorInterface) {
            $authenticator->set($this);
        }

        return $this;
    }

    /**
     * Run the boot method on the connector and request.
     *
     * @return $this
     */
    protected function bootConnectorAndRequest(): static
    {
        $this->connector->boot($this);
        $this->request->boot($this);

        return $this;
    }

    /**
     * Boot every plugin and apply to the payload.
     *
     * @return $this
     * @throws ReflectionException
     */
    protected function bootPlugins(): static
    {
        $connector = $this->connector;
        $request = $this->request;

        $connectorTraits = (new ReflectionClass($connector))->getTraits();
        $requestTraits = (new ReflectionClass($request))->getTraits();

        foreach ($connectorTraits as $connectorTrait) {
            PluginHelper::bootPlugin($this, $connector, $connectorTrait);
        }

        foreach ($requestTraits as $requestTrait) {
            PluginHelper::bootPlugin($this, $request, $requestTrait);
        }

        return $this;
    }

    /**
     * Register any default middleware that should be placed right at the top.
     *
     * @return $this
     */
    protected function registerDefaultMiddleware(): static
    {
        $middleware = $this->middleware();

        // If the PendingSaloonRequest has a mock client then we
        // will add a "MockMiddleware" request pipe which will
        // check to see if there are any mock responses.

        if ($this->isMocking()) {
            $middleware->onRequest(new MockMiddleware($this->getMockClient()));
        }

        // Todo: Register Laravel middleware pipe.

        return $this;
    }

    /**
     * Execute the request pipeline.
     *
     * @return $this
     */
    protected function executeRequestPipeline(): static
    {
        $this->middleware()->executeRequestPipeline($this);

        return $this;
    }

    /**
     * Run the response through a pipeline
     *
     * @param SaloonResponse $response
     * @return SaloonResponse
     */
    public function executeResponsePipeline(SaloonResponse $response): SaloonResponse
    {
        $this->middleware()->executeResponsePipeline($response);

        return $response;
    }

    /**
     * @return SaloonRequest
     */
    public function getRequest(): SaloonRequest
    {
        return $this->request;
    }

    /**
     * @return SaloonConnector
     */
    public function getConnector(): SaloonConnector
    {
        return $this->connector;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return Method
     */
    public function getMethod(): Method
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getResponseClass(): string
    {
        return $this->responseClass;
    }

    /**
     * @return MockClient|null
     */
    public function getMockClient(): ?MockClient
    {
        return $this->mockClient;
    }

    /**
     * Check if the pending Saloon request is being mocked.
     *
     * @return bool
     */
    public function isMocking(): bool
    {
        return $this->mockClient instanceof MockClient;
    }

    /**
     * @return RequestBodyType|null
     */
    public function getDataType(): ?RequestBodyType
    {
        return $this->dataType;
    }

    /**
     * Get the request sender.
     *
     * @return SenderInterface
     */
    public function getSender(): SenderInterface
    {
        return $this->connector->sender();
    }

    /**
     * Set the mock client
     *
     * @param MockClient|null $mockClient
     * @return PendingSaloonRequest
     */
    public function setMockClient(?MockClient $mockClient): static
    {
        $this->mockClient = $mockClient;

        return $this;
    }

    /**
     * Get the mocked response
     *
     * @return MockResponse|null
     */
    public function getMockResponse(): ?MockResponse
    {
        return $this->mockResponse;
    }

    /**
     * Set the mocked response
     *
     * @param MockResponse|null $mockResponse
     * @return PendingSaloonRequest
     */
    public function setMockResponse(?MockResponse $mockResponse): PendingSaloonRequest
    {
        $this->mockResponse = $mockResponse;

        return $this;
    }

    /**
     * Check if the pending request has a mock response
     *
     * @return bool
     */
    public function hasMockResponse(): bool
    {
        return $this->mockResponse instanceof MockResponse;
    }

    /**
     * @return BodyRepository|null
     */
    public function getBody(): ?BodyRepository
    {
        return $this->body;
    }
}
