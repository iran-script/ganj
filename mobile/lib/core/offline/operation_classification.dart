enum OperationClass {
  offlineQueueable,
  cacheableRead,
  onlineRequired,
  onlinePreferredWithQueueFallback,
}

enum OfflineActionType {
  gpsSample,
  puzzleSubmission,
  safeAcknowledgement,
}

OperationClass classifyAction(String action) {
  switch (action) {
    case 'gps_sample':
    case 'safe_acknowledgement':
      return OperationClass.offlineQueueable;
    case 'puzzle_submission':
      return OperationClass.onlinePreferredWithQueueFallback;
    case 'runtime_projection':
    case 'score_projection':
    case 'leaderboard_projection':
      return OperationClass.cacheableRead;
    case 'qr_scan':
    case 'qr_token_consume':
    case 'session_transition':
    case 'device_rebind':
    case 'score_adjustment':
    case 'hint_consume':
    case 'gamemaster_command':
    default:
      return OperationClass.onlineRequired;
  }
}
