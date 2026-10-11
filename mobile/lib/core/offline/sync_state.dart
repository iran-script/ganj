import 'package:flutter/foundation.dart';

enum SyncState {
  online,
  reconnecting,
  offline,
  syncing,
  actionPending,
  syncError
}

class SyncStatus extends ChangeNotifier {
  SyncState state = SyncState.online;
  int pendingCount = 0;
  String? error;

  void setState(SyncState next, {int? pending, String? message}) {
    state = next;
    pendingCount = pending ?? pendingCount;
    error = message;
    notifyListeners();
  }
}
