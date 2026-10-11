class ApiError implements Exception {
  const ApiError({required this.code, required this.message, this.statusCode});

  final String code;
  final String message;
  final int? statusCode;

  String get userMessage => switch (code) {
        'INVALID_CREDENTIALS' => 'شماره موبایل یا رمز عبور صحیح نیست',
        'NETWORK_ERROR' => 'ارتباط با سرور برقرار نشد',
        'VALIDATION_FAILED' =>
          'اطلاعات واردشده معتبر نیست. شماره موبایل و رمز عبور را بررسی کنید.',
        'AUTH_STORAGE_FAILED' => 'ذخیره امن ورود انجام نشد. دوباره تلاش کنید.',
        'INVALID_RESPONSE' => 'پاسخ سرور معتبر نیست. دوباره تلاش کنید.',
        'DEVICE_ID_INVALID' =>
          'شناسه دستگاه معتبر نیست. بازیابی دستگاه لازم است.',
        'AUTHENTICATION_REQUIRED' => 'لطفاً دوباره وارد شوید.',
        'DEVICE_NOT_BOUND' => 'این دستگاه به بازیکن متصل نیست.',
        'QR_INVALID' => 'کد QR معتبر نیست.',
        'QR_REPLAYED' => 'این کد قبلاً استفاده شده است.',
        'QR_OUTSIDE_ALLOWED_AREA' =>
          'برای استفاده از این کد باید در محدوده مجاز باشید.',
        'LOCATION_INVALID' =>
          'موقعیت در دسترس نیست. دسترسی و GPS را بررسی کنید.',
        'LOCATION_STALE' => 'موقعیت قدیمی است. دوباره مکان‌یابی کنید.',
        'ANSWER_REJECTED' => 'پاسخ تأیید نشد. دوباره بررسی کنید.',
        'NETWORK_UNAVAILABLE' => 'ارتباط با سرور برقرار نشد.',
        'LOCATION_ACCURACY_TOO_LOW' => 'دقت مکان‌یابی کافی نیست.',
        'NODE_NOT_ACTIVE' => 'این مرحله دیگر فعال نیست.',
        'RATE_LIMITED' => 'تعداد تلاش‌ها زیاد است؛ کمی بعد دوباره امتحان کنید.',
        'HINT_LIMIT_REACHED' => 'راهنمای دیگری در دسترس نیست.',
        'ENTITLEMENT_REQUIRED' => 'برای این راهنما مجوز لازم است.',
        'IDEMPOTENCY_CONFLICT' => 'درخواست تکراری با داده متفاوت است.',
        'STATE_TRANSITION_INVALID' => 'این عملیات در وضعیت فعلی ممکن نیست.',
        'ACTION_NOT_ALLOWED' => 'این عملیات برای شما مجاز نیست.',
        _ => 'خطایی رخ داد. دوباره تلاش کنید.',
      };

  @override
  String toString() => 'ApiError($code, $statusCode)';
}
