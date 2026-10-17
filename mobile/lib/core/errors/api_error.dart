class ApiError implements Exception {
  const ApiError({
    required this.code,
    required this.message,
    this.statusCode,
    this.details,
  });

  final String code;
  final String message;
  final int? statusCode;
  final Map<String, dynamic>? details;

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
        'AUTHORIZATION_DENIED' =>
          'این حساب در حال حاضر اجازه ورود ندارد. با پشتیبانی تماس بگیرید.',
        'RATE_LIMITED' => _retryMessage,
        'RESOURCE_NOT_FOUND' => 'اطلاعات درخواستی پیدا نشد. صفحه را تازه کنید.',
        'CONFLICT' =>
          'اطلاعات هم‌زمان تغییر کرده است. دوباره بارگیری و تلاش کنید.',
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
        'HINT_LIMIT_REACHED' => 'راهنمای دیگری در دسترس نیست.',
        'ENTITLEMENT_REQUIRED' => 'برای این راهنما مجوز لازم است.',
        'IDEMPOTENCY_CONFLICT' => 'درخواست تکراری با داده متفاوت است.',
        'STATE_TRANSITION_INVALID' => 'این عملیات در وضعیت فعلی ممکن نیست.',
        'ACTION_NOT_ALLOWED' => 'این عملیات برای شما مجاز نیست.',
        'INSECURE_ENDPOINT' =>
          'برای امنیت ورود، نشانی API باید HTTPS معتبر باشد.',
        _ => 'خطایی رخ داد. دوباره تلاش کنید.',
      };

  String? fieldMessage(String field) {
    final fields = details?['fields'];
    if (fields is! Map) {
      return null;
    }
    final messages = fields[field];
    if (messages is List && messages.isNotEmpty && messages.first is String) {
      return _translateValidation(field, messages.first as String);
    }
    return null;
  }

  String get _retryMessage {
    final seconds = details?['retry_after_seconds'];
    if (seconds is int && seconds > 0) {
      final localizedSeconds = seconds.toString().replaceAllMapped(
            RegExp(r'[0-9]'),
            (match) => '۰۱۲۳۴۵۶۷۸۹'[int.parse(match[0]!)],
          );
      return 'تعداد تلاش‌ها زیاد است. $localizedSeconds ثانیه دیگر دوباره امتحان کنید.';
    }
    return 'تعداد تلاش‌ها زیاد است. کمی بعد دوباره امتحان کنید.';
  }

  static String _translateValidation(String field, String message) {
    final label = switch (field) {
      'name' => 'نام',
      'email' => 'ایمیل',
      'mobile' => 'شماره همراه',
      'password' => 'رمز عبور',
      _ => 'اطلاعات',
    };
    if (message.contains('required')) {
      return '$label را وارد کنید.';
    }
    if (field == 'password' && message.contains('12')) {
      return 'رمز عبور باید دست‌کم ۱۲ نویسه باشد.';
    }
    if (field == 'email') {
      return 'قالب ایمیل معتبر نیست.';
    }
    if (field == 'mobile') {
      return 'شماره همراه باید ۷ تا ۱۵ رقم داشته باشد.';
    }
    return '$label معتبر نیست.';
  }

  @override
  String toString() => 'ApiError($code, $statusCode)';
}
