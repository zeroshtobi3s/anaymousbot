<?php

declare(strict_types=1);

namespace PhpBot\Services;

use PhpBot\Repositories\ConversationStateRepository;

final class ConversationService
{
    public const STATE_AWAITING_ANONYMOUS_MESSAGE = 'awaiting_anonymous_message';
    public const STATE_AWAITING_REPLY = 'awaiting_reply';
    public const STATE_AWAITING_BANNED_WORDS = 'awaiting_banned_words';

    private ConversationStateRepository $stateRepository;

    public function __construct(ConversationStateRepository $stateRepository)
    {
        $this->stateRepository = $stateRepository;
    }

    public function setAwaitingAnonymousMessage(int $telegramUserId, int $targetUserId): void
    {
        $this->stateRepository->saveState($telegramUserId, self::STATE_AWAITING_ANONYMOUS_MESSAGE, [
            'target_user_id' => $targetUserId,
        ]);
    }

    public function setAwaitingReply(int $telegramUserId, int $messageId): void
    {
        $this->stateRepository->saveState($telegramUserId, self::STATE_AWAITING_REPLY, [
            'message_id' => $messageId,
        ]);
    }

    public function setAwaitingBannedWords(int $telegramUserId): void
    {
        $this->stateRepository->saveState($telegramUserId, self::STATE_AWAITING_BANNED_WORDS, []);
    }

    /**
     * @return array{state_name:string,payload:array<string,mixed>,updated_at:string}|null
     */
    public function getState(int $telegramUserId): ?array
    {
        return $this->stateRepository->findByTelegramUserId($telegramUserId);
    }

    public function clearState(int $telegramUserId): void
    {
        $this->stateRepository->clearState($telegramUserId);
    }
}

