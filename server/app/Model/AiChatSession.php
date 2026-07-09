<?php

namespace App\Model;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * AI 对话会话 Model
 */
class AiChatSession
{
  const TABLE = 'ai_chat_sessions';

  /**
   * 创建会话
   *
   * @param int $uid 用户ID，游客为0
   * @param int $itemId 项目ID，全局会话为0
   * @param string|null $guestToken 游客标识
   * @return int session_id，失败返回0
   */
  public static function createSession(int $uid, int $itemId, ?string $guestToken = null): int
  {
    $now = time();

    try {
      $id = DB::table(self::TABLE)->insertGetId([
        'uid' => $uid,
        'item_id' => $itemId,
        'guest_token' => $guestToken,
        'last_message_at' => $now,
        'created_at' => $now,
        'is_del' => 0,
      ]);
      return (int) $id;
    } catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * 获取最新活跃会话
   *
   * @param int $uid 用户ID
   * @param int $itemId 项目ID
   * @return array|null
   */
  public static function getActiveSession(int $uid, int $itemId): ?array
  {
    $query = DB::table(self::TABLE)
      ->where('uid', $uid)
      ->where('item_id', $itemId)
      ->where('is_del', 0)
      ->where('last_message_at', '>=', time() - 4 * 3600)
      ->orderBy('last_message_at', 'desc')
      ->first();

    return $query ? (array) $query : null;
  }

  /**
   * 获取游客最新活跃会话
   *
   * @param string $guestToken 游客标识
   * @param int $itemId 项目ID
   * @return array|null
   */
  public static function getActiveSessionForGuest(string $guestToken, int $itemId): ?array
  {
    if ($guestToken === '' || $itemId <= 0) {
      return null;
    }

    $query = DB::table(self::TABLE)
      ->where('guest_token', $guestToken)
      ->where('item_id', $itemId)
      ->where('is_del', 0)
      ->where('last_message_at', '>=', time() - 4 * 3600)
      ->orderBy('last_message_at', 'desc')
      ->first();

    return $query ? (array) $query : null;
  }

  /**
   * 获取会话列表
   *
   * @param int $uid 用户ID
   * @param int $itemId 项目ID
   * @param int $limit 最大数量
   * @return array 每项含 title / last_message_at / message_count
   */
  public static function getSessionList(int $uid, int $itemId, int $limit = 50): array
  {
    $sessions = DB::table(self::TABLE)
      ->where('uid', $uid)
      ->where('item_id', $itemId)
      ->where('is_del', 0)
      ->orderBy('last_message_at', 'desc')
      ->limit($limit)
      ->get()
      ->all();

    if (empty($sessions)) {
      return [];
    }

    $sessionIds = array_map(function ($s) {
      return (int) $s->id;
    }, $sessions);

    // 批量查询：每个 session 的首条 user 消息
    $firstMessages = AiChatMessage::getFirstUserMessagesBatch($sessionIds);

    // 批量查询：每个 session 的消息数
    $messageCounts = AiChatMessage::getMessageCountsBatch($sessionIds);

    $result = [];
    foreach ($sessions as $session) {
      $sessionId = (int) $session->id;

      // 首条用户消息前20字作为标题
      $title = '';
      $firstMsg = $firstMessages[$sessionId] ?? null;
      if ($firstMsg && !empty($firstMsg['content'])) {
        $title = mb_substr($firstMsg['content'], 0, 20);
        if (mb_strlen($firstMsg['content']) > 20) {
          $title .= '...';
        }
      }

      $result[] = [
        'session_id' => $sessionId,
        'title' => $title,
        'last_message_at' => (int) $session->last_message_at,
        'created_at' => (int) $session->created_at,
        'message_count' => $messageCounts[$sessionId] ?? 0,
      ];
    }

    return $result;
  }

  /**
   * 软删除会话及其所有消息
   *
   * @param int $sessionId 会话ID
   * @param int $uid 用户ID（归属校验，0=游客）
   * @param string|null $guestToken 游客标识（uid=0 时校验）
   * @return bool
   */
  public static function deleteSession(int $sessionId, int $uid = 0, ?string $guestToken = null): bool
  {
    if ($sessionId <= 0) {
      return false;
    }

    try {
      // 归属校验：确保只能删除自己的会话
      $query = DB::table(self::TABLE)->where('id', $sessionId);
      if ($uid > 0) {
        $query->where('uid', $uid);
      } else {
        $query->where('uid', 0);
        if ($guestToken !== null && $guestToken !== '') {
          $query->where('guest_token', $guestToken);
        }
      }

      $session = $query->first();
      if (!$session) {
        return false;
      }

      // 先删除关联消息，再删除会话（保持语义一致：都硬删）
      AiChatMessage::deleteBySession($sessionId);

      // 硬删除会话（与消息硬删保持一致）
      DB::table(self::TABLE)
        ->where('id', $sessionId)
        ->delete();

      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * 清空会话消息但保留 session
   *
   * @param int $sessionId 会话ID
   * @param int $uid 用户ID（归属校验，0=游客）
   * @param string|null $guestToken 游客标识（uid=0 时校验）
   * @return bool
   */
  public static function resetSession(int $sessionId, int $uid = 0, ?string $guestToken = null): bool
  {
    if ($sessionId <= 0) {
      return false;
    }

    try {
      // 归属校验：确保只能重置自己的会话
      $query = DB::table(self::TABLE)->where('id', $sessionId)->where('is_del', 0);
      if ($uid > 0) {
        $query->where('uid', $uid);
      } else {
        $query->where('uid', 0);
        if ($guestToken !== null && $guestToken !== '') {
          $query->where('guest_token', $guestToken);
        }
      }

      $session = $query->first();
      if (!$session) {
        return false;
      }

      AiChatMessage::deleteBySession($sessionId);

      // 重置最后消息时间
      DB::table(self::TABLE)
        ->where('id', $sessionId)
        ->update(['last_message_at' => time()]);

      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * 超过上限时清理最久未活跃的会话
   *
   * @param int $uid 用户ID
   * @param int $itemId 项目ID
   * @param int $maxCount 最大保留数量
   * @param string|null $guestToken 游客标识（uid=0 时按此隔离）
   * @return void
   */
  public static function cleanOldSessions(int $uid, int $itemId, int $maxCount = 50, ?string $guestToken = null): void
  {
    // Fix 2.1: 当 uid=0（游客）时，必须按 guest_token 隔离，避免跨用户删除
    $baseQuery = DB::table(self::TABLE)
      ->where('item_id', $itemId)
      ->where('is_del', 0);

    if ($uid > 0) {
      $baseQuery->where('uid', $uid);
    } else {
      $baseQuery->where('uid', 0);
      if ($guestToken !== null && $guestToken !== '') {
        $baseQuery->where('guest_token', $guestToken);
      }
    }

    // 克隆基础条件用于 count 和取列表
    $countQuery = clone $baseQuery;
    $count = $countQuery->count();

    if ($count <= $maxCount) {
      return;
    }

    $excess = $count - $maxCount;

    // 按最后消息时间升序，取最旧的会话ID列表
    $listQuery = clone $baseQuery;
    $oldSessionIds = $listQuery
      ->orderBy('last_message_at', 'asc')
      ->limit($excess)
      ->pluck('id')
      ->map(function ($id) {
        return (int) $id;
      })
      ->all();

    if (empty($oldSessionIds)) {
      return;
    }

    // 批量删除会话（硬删，与消息硬删保持一致）
    DB::table(self::TABLE)
      ->whereIn('id', $oldSessionIds)
      ->delete();

    // 批量物理删除消息
    AiChatMessage::deleteBySessions($oldSessionIds);
  }

  /**
   * 更新最后消息时间
   *
   * @param int $sessionId 会话ID
   * @return void
   */
  public static function updateLastMessageAt(int $sessionId): void
  {
    if ($sessionId <= 0) {
      return;
    }

    DB::table(self::TABLE)
      ->where('id', $sessionId)
      ->update(['last_message_at' => time()]);
  }

  /**
   * 懒清理：超过 200 条消息或超过 60 天时，1% 概率触发清理
   *
   * 清理策略：保留最近 100 条消息，删除更早的消息
   *
   * @param int $sessionId 会话ID
   * @return void
   */
  public static function maybeCleanup(int $sessionId): void
  {
    if ($sessionId <= 0) {
      return;
    }

    // 1% 概率触发
    if (mt_rand(1, 100) !== 1) {
      return;
    }

    // 检查消息数是否超过 200
    $messageCount = AiChatMessage::getMessageCount($sessionId);
    if ($messageCount <= 200) {
      // 检查是否超过 60 天
      $session = DB::table(self::TABLE)
        ->where('id', $sessionId)
        ->first();
      if (!$session) {
        return;
      }
      $daysSinceLastMessage = (time() - (int) $session->last_message_at) / 86400;
      if ($daysSinceLastMessage <= 60) {
        return;
      }
    }

    // 保留最近 100 条消息，删除更早的
    $keepCount = 100;
    $thresholdId = DB::table(AiChatMessage::TABLE)
      ->where('session_id', $sessionId)
      ->orderBy('id', 'desc')
      ->offset($keepCount)
      ->limit(1)
      ->value('id');

    if ($thresholdId === null) {
      return;
    }

    DB::table(AiChatMessage::TABLE)
      ->where('session_id', $sessionId)
      ->where('id', '<', (int) $thresholdId)
      ->delete();
  }
}
