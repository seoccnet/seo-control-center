<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Client\CurlHttpClient;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\LayoutWidgets;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Alerts\DigestSummarizer;
use SeoControlCenter\Modules\Assistant\Anthropic\AnthropicModel;
use SeoControlCenter\Modules\Assistant\Controllers\AssistantController;
use SeoControlCenter\Modules\Assistant\Controllers\AssistantSettingsController;
use SeoControlCenter\Modules\Assistant\OpenAi\OpenAiModel;
use SeoControlCenter\Modules\Assistant\Persistence\PdoAssistantSettingsRepository;
use SeoControlCenter\Modules\Assistant\Persistence\PdoConversationRepository;
use SeoControlCenter\Modules\Assistant\Persistence\PdoFixPlanRepository;
use SeoControlCenter\Modules\Mcp\Server\McpServer;
use SeoControlCenter\Modules\Opportunities\OpportunityService;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;

/**
 * The AI assistant (ADR-028): a floating Claude or GPT chat on every page that
 * reads the workspace's data through the MCP tools and can mark
 * opportunities after the user confirms. Set up per workspace under
 * Integrations → AI assistant.
 */
final class AssistantModule implements Module
{
    public function name(): string
    {
        return 'assistant';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(AssistantSettingsRepository::class, static fn(Container $c): AssistantSettingsRepository => new PdoAssistantSettingsRepository(
            $c->make(Connection::class),
            $c->make(Encrypter::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ConversationRepository::class, static fn(Container $c): ConversationRepository => new PdoConversationRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(LanguageModels::class, static function (Container $c): LanguageModels {
            $config = $c->make(Config::class);
            $http = new CurlHttpClient(min(60, max(10, $config->int('assistant.timeout', 60))), 'SEO-Control-Center/' . $c->get('app.version'));
            $providers = [];
            foreach ($config->array('assistant.providers') as $provider => $settings) {
                if (!is_string($provider) || !is_array($settings) || !is_array($settings['models'] ?? null)) {
                    continue;
                }
                $models = [];
                foreach ($settings['models'] as $id => $model) {
                    if (is_string($id) && is_array($model) && is_string($model['label'] ?? null)) {
                        $input = (float) ($model['input'] ?? 0);
                        $models[$id] = ['label' => $model['label'], 'input' => $input, 'output' => (float) ($model['output'] ?? 0), 'cached' => (float) ($model['cached'] ?? $input * 0.1)];
                    }
                }
                $client = match ($provider) {
                    'anthropic' => new AnthropicModel($http, $models),
                    'openai' => new OpenAiModel($http, $models),
                    default => null,
                };
                if ($client !== null && $models !== []) {
                    $providers[$provider] = ['label' => is_string($settings['label'] ?? null) ? $settings['label'] : $provider, 'model' => $client];
                }
            }

            return new LanguageModels($providers);
        });
        $container->singleton(FixPlanRepository::class, static fn(Container $c): FixPlanRepository => new PdoFixPlanRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(AssistantTools::class, static fn(Container $c): AssistantTools => new AssistantTools(
            $c->make(McpServer::class),
            $c->make(OpportunityRepository::class),
            $c->make(OpportunityService::class),
            $c->make(SiteAuditService::class),
            $c->make(FixPlanRepository::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(AssistantSetup::class, static fn(Container $c): AssistantSetup => new AssistantSetup(
            $c->make(AssistantSettingsRepository::class),
            $c->make(LanguageModels::class),
            $c->make(UsageLedger::class),
            $c->make(Authorizer::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(AssistantService::class, static fn(Container $c): AssistantService => new AssistantService(
            $c->make(AssistantSettingsRepository::class),
            $c->make(ConversationRepository::class),
            $c->make(FixPlanRepository::class),
            $c->make(LanguageModels::class),
            $c->make(AssistantTools::class),
            $c->make(UsageLedger::class),
            $c->make(ProjectRepository::class),
            $c->make(Authorizer::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            min(8192, max(256, $c->make(Config::class)->int('assistant.max_tokens', 2048))),
        ));
        $container->singleton(DigestSummarizer::class, static fn(Container $c): DigestSummarizer => new AssistantDigestSummarizer(
            $c->make(AssistantSettingsRepository::class),
            $c->make(LanguageModels::class),
            $c->make(UsageLedger::class),
            $c->make(FeatureManager::class),
            $c->make(LoggerInterface::class),
            $c->make(RateLimiter::class),
        ));
        $container->singleton(\SeoControlCenter\Domain\Research\SeedSuggester::class, static fn(Container $c): \SeoControlCenter\Domain\Research\SeedSuggester => new AssistantSeedSuggester(
            $c->make(AssistantSettingsRepository::class),
            $c->make(LanguageModels::class),
            $c->make(UsageLedger::class),
            $c->make(FeatureManager::class),
            $c->make(LoggerInterface::class),
            $c->make(RateLimiter::class),
        ));
        $container->singleton(\SeoControlCenter\Domain\Comparison\AnalysisExplainer::class, static fn(Container $c): \SeoControlCenter\Domain\Comparison\AnalysisExplainer => new AssistantAnalysisExplainer(
            $c->make(AssistantSettingsRepository::class),
            $c->make(LanguageModels::class),
            $c->make(UsageLedger::class),
            $c->make(FeatureManager::class),
            $c->make(LoggerInterface::class),
            $c->make(RateLimiter::class),
        ));
        $container->singleton(AssistantWidget::class, static fn(Container $c): AssistantWidget => new AssistantWidget($c->make(AssistantService::class), $c->make(AssistantSettingsRepository::class), $c->make(LanguageModels::class)));
        $container->make(LayoutWidgets::class)->add(LayoutWidgets::FLOATING, static fn(): AssistantWidget => $container->make(AssistantWidget::class));

        $container->singleton(AssistantController::class, static fn(Container $c): AssistantController => new AssistantController(
            $c->make(AssistantService::class),
            $c->make(ResponseFactory::class),
        ));
        $container->singleton(AssistantSettingsController::class, static fn(Container $c): AssistantSettingsController => new AssistantSettingsController(
            $c->make(AssistantSetup::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(LoggerInterface::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/assistant', AssistantController::class, 'state', 'assistant.state');
        $routes->post('/assistant/messages', AssistantController::class, 'send');
        $routes->post('/assistant/continue', AssistantController::class, 'continue');
        $routes->post('/assistant/confirm', AssistantController::class, 'confirm');
        $routes->post('/assistant/new', AssistantController::class, 'reset');
        $routes->get('/assistant/plans/{plan:' . Uuid::ROUTE_PATTERN . '}', AssistantController::class, 'plan', 'assistant.plan');

        $routes->get('/integrations/assistant', AssistantSettingsController::class, 'show', 'integrations.assistant');
        $routes->post('/integrations/assistant', AssistantSettingsController::class, 'connect');
        $routes->post('/integrations/assistant/model', AssistantSettingsController::class, 'model');
        $routes->post('/integrations/assistant/budget', AssistantSettingsController::class, 'budget');
        $routes->post('/integrations/assistant/disconnect', AssistantSettingsController::class, 'disconnect');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
