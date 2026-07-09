<?php

namespace App\Model;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * AI 对话消息 Model
 */
class AiChatMessage
{
  const TABLE = 'ai_chat_messages';

  /**
   * 添加消息
   *
   * @param int $sessionId 会话ID
   * @param string $role 角色：user / assistant
   * @param string $content 消息内容
   * @return int msg_id，失败返回0
   */
  public static function addMessage(int $sessionId, string $role, string $content): int
  {
    if ($sessionId <= 0 || !in_array($role, ['user', 'assistant'], true)) {
      return 0;
    }

    try {
      $data = [
        'session_id' => $sessionId,
        'role' => $role,
        'content' => $content,
        'created_at' => time(),
      ];

      $id = DB::table(self::TABLE)->insertGetId($data);
      return (int) $id;
    } catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * 获取最近 N 条消息（用于 LLM 上下文）
   *
   * @param int $sessionId 会话ID
   * @param int $limit 最大数量
   * @param int $ownerUid 归属校验：仅返回属于该用户的会话消息（0=不校验，用于游客等场景）
   * @return array
   */
  public static function getMessages(int $sessionId, int $limit = 40, int $ownerUid = 0): array
  {
    if ($sessionId <= 0) {
      return [];
    }

    $query = DB::table(self::TABLE)
      ->where('session_id', $sessionId);

    // 纵深防御：当传入 ownerUid 时，确保会话属于该用户，防止越权读取他人会话消息
    if ($ownerUid > 0) {
      $query->whereExists(function ($q) use ($sessionId, $ownerUid) {
        $q->select(DB::raw(1))
          ->from('ai_chat_sessions')
          ->where('id', $sessionId)
          ->where('uid', $ownerUid);
      });
    }

    $rows = $query->orderBy('id', 'desc')->limit($limit)->get()->all();

    // 按 id 升序返回（时间正序）
    return array_map(function ($row) {
      return (array) $row;
    }, array_reverse($rows));
  }

  /**
   * 获取指定消息之前的消息（用于重新生成）
   *
   * @param int $sessionId 会话ID
   * @param int $beforeMsgId 消息ID（不包含此消息）
   * @param int $limit 最大数量
   * @param int $ownerUid 归属校验：仅返回属于该用户的会话消息（0=不校验）
   * @return array 按 id 升序
   */
  public static function getMessagesBefore(int $sessionId, int $beforeMsgId, int $limit = 40, int $ownerUid = 0): array
  {
    if ($sessionId <= 0 || $beforeMsgId <= 0) {
      return [];
    }

    $query = DB::table(self::TABLE)
      ->where('session_id', $sessionId)
      ->where('id', '<', $beforeMsgId);

    if ($ownerUid > 0) {
      $query->whereExists(function ($q) use ($sessionId, $ownerUid) {
        $q->select(DB::raw(1))
          ->from('ai_chat_sessions')
          ->where('id', $sessionId)
          ->where('uid', $ownerUid);
      });
    }

    $rows = $query->orderBy('id', 'desc')->limit($limit)->get()->all();

    // 按 id 升序返回
    return array_map(function ($row) {
      return (array) $row;
    }, array_reverse($rows));
  }

  /**
   * 更新反馈（1=赞，2=踩，0=取消）
   *
   * @param int $msgId 消息ID
   * @param int $feedback 反馈值
   * @param int $sessionId 会话ID（归属校验，0=不校验）
   * @return bool
   */
  public static function updateFeedback(int $msgId, int $feedback, int $sessionId = 0): bool
  {
    if ($msgId <= 0 || !in_array($feedback, [0, 1, 2], true)) {
      return false;
    }

    try {
      $query = DB::table(self::TABLE)->where('id', $msgId);
      // Fix 2.2: Model 层归属校验，确保只能操作自己会话内的消息
      if ($sessionId > 0) {
        $query->where('session_id', $sessionId);
      }
      return $query->update(['feedback' => $feedback]) > 0;
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * 删除会话所有消息（用于 reset）
   *
   * @param int $sessionId 会话ID
   * @return bool
   */
  public static function deleteBySession(int $sessionId): bool
  {
    if ($sessionId <= 0) {
      return false;
    }

    try {
      DB::table(self::TABLE)
        ->where('session_id', $sessionId)
        ->delete();
      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * 获取会话消息数
   *
   * @param int $sessionId 会话ID
   * @return int
   */
  public static function getMessageCount(int $sessionId): int
  {
    if ($sessionId <= 0) {
      return 0;
    }

    return (int) DB::table(self::TABLE)
      ->where('session_id', $sessionId)
      ->count();
  }

  /**
   * 获取首条用户消息（用于会话标题）
   *
   * @param int $sessionId 会话ID
   * @return array|null
   */
  public static function getFirstUserMessage(int $sessionId): ?array
  {
    if ($sessionId <= 0) {
      return null;
    }

    $row = DB::table(self::TABLE)
      ->where('session_id', $sessionId)
      ->where('role', 'user')
      ->orderBy('id', 'asc')
      ->first();

    return $row ? (array) $row : null;
  }

  /**
   * 批量获取每个 session 的首条 user 消息
   *
   * @param array $sessionIds 会话ID数组
   * @return array [session_id => ['content' => ...], ...]
   */
  public static function getFirstUserMessagesBatch(array $sessionIds): array
  {
    if (empty($sessionIds)) {
      return [];
    }

    // 子查询：每个 session 中最小的 id（即首条 user 消息）
    $sub = DB::table(self::TABLE)
      ->select('session_id', DB::raw('MIN(id) as min_id'))
      ->where('role', 'user')
      ->whereIn('session_id', $sessionIds)
      ->groupBy('session_id');

    // join 回主表取完整内容
    $rows = DB::table(self::TABLE)
      ->joinSub($sub, 'first', function ($join) {
        $join->on(self::TABLE . '.id', '=', 'first.min_id');
      })
      ->select(self::TABLE . '.session_id', self::TABLE . '.content')
      ->get()
      ->all();

    $result = [];
    foreach ($rows as $row) {
      $result[(int) $row->session_id] = ['content' => $row->content];
    }

    return $result;
  }

  /**
   * 批量获取每个 session 的消息数
   *
   * @param array $sessionIds 会话ID数组
   * @return array [session_id => count, ...]
   */
  public static function getMessageCountsBatch(array $sessionIds): array
  {
    if (empty($sessionIds)) {
      return [];
    }

    $rows = DB::table(self::TABLE)
      ->select('session_id', DB::raw('COUNT(*) as cnt'))
      ->whereIn('session_id', $sessionIds)
      ->groupBy('session_id')
      ->get()
      ->all();

    $result = [];
    foreach ($rows as $row) {
      $result[(int) $row->session_id] = (int) $row->cnt;
    }

    return $result;
  }

  /**
   * 批量删除多个 session 的所有消息
   *
   * @param array $sessionIds 会话ID数组
   * @return bool
   */
  public static function deleteBySessions(array $sessionIds): bool
  {
    if (empty($sessionIds)) {
      return true;
    }

    try {
      DB::table(self::TABLE)
        ->whereIn('session_id', $sessionIds)
        ->delete();
      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }
}
