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
  String? outcome;

  String? get userMessage => switch (state) {
        SyncState.online => outcome,
        SyncState.reconnecting => 'در حال اتصال دوباره…',
        SyncState.offline =>
          'اتصال قطع شده؛ وضعیت نمایش‌داده‌شده آخرین وضعیت ذخیره‌شده است.',
        SyncState.syncing => 'در حال همگام‌سازی…',
        SyncState.actionPending => 'اقدام ذخیره شد و پس از اتصال ارسال می‌شود',
        SyncState.syncError => switch (error) {
            'AUTHENTICATION_REQUIRED' => 'برای همگام‌سازی دوباره وارد شوید.',
            'DEVICE_NOT_BOUND' => 'بازیابی اتصال دستگاه به بازیکن لازم است.',
            'SESSION_PAUSED' => 'بازی متوقف است؛ ارسال اقدام‌ها منتظر می‌ماند.',
            'SESSION_FINISHED' ||
            'SESSION_CANCELLED' =>
              'جلسه پایان یافته؛ اقدام‌های ذخیره‌شده ارسال نمی‌شوند.',
            _ =>
              'همگام‌سازی انجام نشد. اتصال و وضعیت بازی را دوباره بررسی کنید.',
          },
      };

  void setState(SyncState next, {int? pending, String? message}) {
    state = next;
    pendingCount = pending ?? pendingCount;
    error = message;
    notifyListeners();
  }
}
