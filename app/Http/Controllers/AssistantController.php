<?php

namespace App\Http\Controllers;

use App\Ai\AiManager;
use App\Ai\AssistantService;
use App\Ai\ContextRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The optional AI assistant: a full-page conversation UI
 * (resources/js/pages/Assistant.tsx) plus the contextual drawer
 * (resources/js/features/assistant/AssistantDrawer.tsx). Every action
 * here degrades cleanly when AI is disabled or unavailable — App\Ai\
 * AiManager::provider() never throws, so index() always renders 200 with
 * an honest `status` prop, and message() always redirects back to a
 * normal page rather than a 500 (see App\Ai\AssistantService::
 * sendMessage()'s own null-provider branch, which persists a plain "AI is
 * unavailable" assistant turn instead of attempting anything).
 *
 * There is deliberately no approve/reject/execute action on this
 * controller. A tool-proposed config change or RCON command is a normal
 * App\Models\Operation, reviewed and approved through the EXISTING
 * config/console approval routes
 * (App\Http\Controllers\ConfigController::approve(),
 * App\Http\Controllers\ConsoleController::approve()) — the assistant UI
 * only links to those, it never gains a new way to approve anything.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly AssistantService $assistant,
    ) {}

    public function index(Request $request): Response
    {
        $config = $this->manager->configuration();
        $provider = $this->manager->provider();

        $status = match (true) {
            $provider !== null => 'ready',
            $config->activeProvider === null => 'disabled',
            default => 'unavailable',
        };

        $conversationId = $request->query('conversation');

        $conversation = is_string($conversationId)
            ? AiConversation::query()->find($conversationId)
            : AiConversation::query()->latest('updated_at')->first();

        return Inertia::render('Assistant', [
            'status' => $status,
            // "AI is unavailable" / "AI is disabled" are literal, honest
            // strings — never a generic "something went wrong" — shown
            // directly in the assistant page's PageState (see
            // resources/js/components/craftkeeper/PageState.tsx, which
            // already ships 'ai-disabled'/'ai-unavailable' states).
            'statusMessage' => match ($status) {
                'disabled' => 'AI is disabled — no provider is configured yet.',
                'unavailable' => 'AI is unavailable. The configured provider did not respond just now; every other CraftKeeper feature is unaffected.',
                default => null,
            },
            'provider' => $config->activeProvider,
            'ollamaAllowUnredacted' => $config->ollamaAllowUnredacted,
            'conversations' => AiConversation::query()
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get()
                ->map(fn (AiConversation $c): array => [
                    'id' => $c->id,
                    'title' => $c->title ?? 'New conversation',
                    'updatedAt' => $c->updated_at?->toIso8601String(),
                ])
                ->values(),
            'conversation' => $conversation instanceof AiConversation ? $this->presentConversation($conversation) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'config_path' => ['nullable', 'string', 'max:512'],
        ]);

        $conversation = AiConversation::query()->create([
            'provider' => $this->manager->configuration()->activeProvider,
            'context_scope' => array_filter(['configPath' => $validated['config_path'] ?? null]),
        ]);

        return redirect("/assistant?conversation={$conversation->id}");
    }

    public function message(Request $request, AiConversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $scope = is_array($conversation->context_scope) ? $conversation->context_scope : [];
        $configPath = is_string($scope['configPath'] ?? null) ? $scope['configPath'] : null;

        $this->assistant->sendMessage($conversation, $validated['message'], new ContextRequest($configPath));

        return redirect("/assistant?conversation={$conversation->id}");
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversation(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'contextScope' => $conversation->context_scope,
            'messages' => $conversation->messages()->get()
                ->map(fn (AiMessage $message): array => $this->presentMessage($message))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(AiMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'citations' => $message->citations ?? [],
            'toolCalls' => $message->tool_calls ?? [],
            'redactionDisclosures' => $message->redaction_disclosures ?? [],
            'provider' => $message->provider,
            'error' => $message->error,
            'createdAt' => $message->created_at?->toIso8601String(),
        ];
    }
}
