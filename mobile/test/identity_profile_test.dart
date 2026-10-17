import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/nexo_components.dart';
import 'package:nexo_player/app/app.dart';
import 'package:nexo_player/app/nexo_theme.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/auth/user_profile.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/auth/register_page.dart';
import 'package:nexo_player/features/auth/login_page.dart';
import 'package:nexo_player/features/profile/profile_page.dart';
import 'package:nexo_player/features/profile/profile_repository.dart';

const deviceUuid = '9b93408e-778f-4619-b4f5-c01ea395b895';

Map<String, dynamic> profileJson({
  String name = 'آرمان',
  String email = 'arman@example.test',
  String mobile = '09120000001',
}) =>
    {
      'id': 'public-user-id',
      'name': name,
      'email': email,
      'mobile': mobile,
      'status': 'ACTIVE',
      'roles': ['PLAYER'],
    };

Widget host(Widget child, {TextScaler? textScaler}) => MaterialApp(
      theme: NexoTheme.data,
      locale: const Locale('fa'),
      home: Builder(
        builder: (context) => Directionality(
          textDirection: TextDirection.rtl,
          child: MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: textScaler ?? const TextScaler.linear(1)),
            child: child,
          ),
        ),
      ),
    );

http.Response jsonResponse(Map<String, dynamic> body, int statusCode) =>
    http.Response.bytes(
      utf8.encode(jsonEncode(body)),
      statusCode,
      headers: const {'content-type': 'application/json; charset=utf-8'},
    );

void main() {
  test('Persian and Arabic mobile digits normalize to API digits', () {
    expect(UserProfile.normalizeDigits('+۹۱۲٣'), '+9123');
  });

  test('registration posts only contract fields and restores device identity',
      () async {
    final store = TestSecureStore();
    http.Request? sentRequest;
    final api = ApiClient(
      store: store,
      client: MockClient((request) async {
        sentRequest = request;
        expect(request.url.path, endsWith('/auth/register'));
        return jsonResponse({
          'data': {
            'user': profileJson(),
            'token': 'new-secret-token',
            'device': {'device_uuid': deviceUuid},
          },
        }, 201);
      }),
    );
    final auth = AuthController(api);

    await auth.register(
      name: 'آرمان',
      email: 'arman@example.test',
      mobile: '',
      password: 'secure-password-12',
    );

    final body = jsonDecode(sentRequest!.body) as Map<String, dynamic>;
    expect(body, {
      'name': 'آرمان',
      'email': 'arman@example.test',
      'password': 'secure-password-12',
      'device_uuid': deviceUuid,
      'device_name': 'NEXO Player',
      'platform': 'android',
      'app_version': '0.1.0',
    });
    expect(sentRequest!.headers['Authorization'], isNull);
    expect(auth.authenticated, isTrue);
    expect(auth.profile?.id, 'public-user-id');
    expect(await api.token(), 'new-secret-token');
    expect(await api.deviceId(), deviceUuid);
    auth.dispose();
  });

  test('profile GET and PATCH use only supported writable fields', () async {
    final store = TestSecureStore()..values['nexo.access_token'] = 'token';
    final requests = <http.Request>[];
    final api = ApiClient(
      store: store,
      client: MockClient((request) async {
        requests.add(request);
        if (request.method == 'GET') {
          return jsonResponse({'data': profileJson()}, 200);
        }
        return jsonResponse({'data': profileJson(name: 'آرمان جدید')}, 200);
      }),
    );
    final repository = ProfileRepository(api);

    final loaded = await repository.load();
    final saved = await repository.update(
        name: ' آرمان جدید ', email: 'arman@example.test', mobile: '');

    expect(loaded, isA<UserProfile>());
    expect(loaded.status, 'ACTIVE');
    expect(saved.name, 'آرمان جدید');
    expect(requests.map((request) => request.method), ['GET', 'PATCH']);
    expect(requests.first.headers['X-Device-Id'], deviceUuid);
    final patch = jsonDecode(requests.last.body) as Map<String, dynamic>;
    expect(patch, {
      'name': 'آرمان جدید',
      'email': 'arman@example.test',
      'mobile': null,
    });
    expect(patch.keys, unorderedEquals(['name', 'email', 'mobile']));
    expect(patch, isNot(contains('password')));
    expect(patch, isNot(contains('roles')));
  });

  test('API validation details become actionable Persian text', () async {
    final api = ApiClient(
      store: TestSecureStore(),
      client: MockClient((_) async => http.Response(
            jsonEncode({
              'error': {
                'code': 'VALIDATION_FAILED',
                'message': 'Invalid fields.',
                'details': {
                  'fields': {
                    'password': ['The password must be at least 12 characters.']
                  }
                },
              },
            }),
            422,
          )),
    );

    await expectLater(
      api.post('/auth/register', body: const {}),
      throwsA(isA<ApiError>().having(
          (failure) => failure.fieldMessage('password'),
          'Persian field feedback',
          'رمز عبور باید دست‌کم ۱۲ نویسه باشد.')),
    );
  });

  testWidgets('registration shows validation, loading, server error and retry',
      (tester) async {
    final firstResponse = Completer<http.Response>();
    var attempts = 0;
    final auth = AuthController(ApiClient(
      store: TestSecureStore(),
      client: MockClient((_) {
        attempts++;
        if (attempts == 1) {
          return firstResponse.future;
        }
        return Future.value(jsonResponse({
          'data': {
            'user': profileJson(),
            'token': 'registered-token',
            'device': {'device_uuid': deviceUuid},
          }
        }, 201));
      }),
    ));
    await tester.pumpWidget(host(RegisterPage(auth: auth)));

    await tester.ensureVisible(find.widgetWithText(FilledButton, 'ساخت حساب'));
    await tester.tap(find.widgetWithText(FilledButton, 'ساخت حساب'));
    await tester.pumpAndSettle();
    expect(find.text('نام را وارد کنید.'), findsOneWidget);
    expect(find.text('ایمیل یا شماره همراه را وارد کنید.'), findsWidgets);

    final fields = find.byType(TextFormField);
    await tester.enterText(fields.at(0), 'آرمان');
    await tester.enterText(fields.at(1), 'arman@example.test');
    await tester.enterText(fields.at(3), 'secure-password-12');
    await tester.enterText(fields.at(4), 'secure-password-12');
    await tester.ensureVisible(find.widgetWithText(FilledButton, 'ساخت حساب'));
    await tester.tap(find.widgetWithText(FilledButton, 'ساخت حساب'));
    await tester.pump();
    expect(find.text('در حال ساخت حساب…'), findsOneWidget);
    expect(attempts, 1);

    firstResponse.complete(jsonResponse({
      'error': {
        'code': 'RATE_LIMITED',
        'message': 'Slow down.',
        'details': {'retry_after_seconds': 3},
      }
    }, 429));
    await tester.pumpAndSettle();
    expect(
        find.text('تعداد تلاش‌ها زیاد است. ۳ ثانیه دیگر دوباره امتحان کنید.'),
        findsOneWidget);
    expect(
        tester.widget<FilledButton>(find.byType(FilledButton).first).onPressed,
        isNotNull);

    await tester.ensureVisible(find.widgetWithText(FilledButton, 'ساخت حساب'));
    await tester.tap(find.widgetWithText(FilledButton, 'ساخت حساب'));
    await tester.pumpAndSettle();
    expect(attempts, 2);
    expect(auth.authenticated, isTrue);
    expect(tester.takeException(), isNull);
    auth.dispose();
  });

  testWidgets('profile retries a failed load and saves editable fields',
      (tester) async {
    var reads = 0;
    http.Request? patchRequest;
    final api = ApiClient(
      store: TestSecureStore()..values['nexo.access_token'] = 'profile-token',
      client: MockClient((request) async {
        if (request.method == 'GET') {
          reads++;
          if (reads == 1) {
            return jsonResponse({
              'error': {'code': 'NETWORK_ERROR', 'message': ''}
            }, 503);
          }
          return jsonResponse({'data': profileJson()}, 200);
        }
        patchRequest = request;
        return jsonResponse({'data': profileJson(name: 'آرمان به‌روز')}, 200);
      }),
    );
    final auth = AuthController(api);
    await tester.pumpWidget(host(ProfilePage(auth: auth)));
    await tester.pumpAndSettle();

    expect(find.text('تلاش دوباره'), findsOneWidget);
    await tester.tap(find.text('تلاش دوباره'));
    await tester.pumpAndSettle();
    expect(find.text('اطلاعات قابل‌ویرایش'), findsOneWidget);
    expect(reads, 2);

    await tester.enterText(find.byType(TextFormField).at(0), 'آرمان به‌روز');
    await tester.ensureVisible(find.text('ذخیره تغییرات'));
    await tester.tap(find.text('ذخیره تغییرات'));
    await tester.pumpAndSettle();
    expect(find.text('پروفایل با موفقیت ذخیره شد.'), findsOneWidget);
    expect(auth.profile?.name, 'آرمان به‌روز');
    expect(patchRequest?.method, 'PATCH');
    expect(jsonDecode(patchRequest!.body), {
      'name': 'آرمان به‌روز',
      'email': 'arman@example.test',
      'mobile': '09120000001',
    });
    expect(tester.takeException(), isNull);
    auth.dispose();
  });

  testWidgets('shared empty and offline states are visible and retryable',
      (tester) async {
    var retries = 0;
    await tester.pumpWidget(host(Scaffold(
      body: ListView(children: [
        const NexoEmptyView(
            title: 'موردی نیست', message: 'بعداً دوباره بررسی کنید.'),
        NexoErrorView(
            message: 'اتصال برقرار نیست.',
            offline: true,
            onRetry: () => retries++),
      ]),
    )));

    expect(find.text('موردی نیست'), findsOneWidget);
    expect(find.byIcon(Icons.cloud_off_outlined), findsOneWidget);
    await tester.tap(find.text('تلاش دوباره'));
    expect(retries, 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets('login and registration scroll at narrow width and large text',
      (tester) async {
    tester.view.physicalSize = const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final auth = AuthController(ApiClient(
      store: TestSecureStore(),
      client: MockClient((_) async => jsonResponse({'data': []}, 200)),
    ));
    const largeText = TextScaler.linear(1.8);

    await tester.pumpWidget(host(LoginPage(auth: auth), textScaler: largeText));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.ensureVisible(find.text('ساخت حساب بازیکن'));
    expect(find.text('ساخت حساب بازیکن'), findsOneWidget);

    await tester
        .pumpWidget(host(RegisterPage(auth: auth), textScaler: largeText));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.ensureVisible(find.text('تکرار رمز عبور'));
    expect(find.text('تکرار رمز عبور'), findsOneWidget);
    auth.dispose();
  });

  testWidgets('expired token clears protected navigation and returns to login',
      (tester) async {
    var profileReads = 0;
    final store = TestSecureStore()..values['nexo.access_token'] = 'old-token';
    final api = ApiClient(
      store: store,
      client: MockClient((request) async {
        if (request.url.path.endsWith('/me')) {
          profileReads++;
          if (profileReads > 1) {
            return jsonResponse({
              'error': {'code': 'AUTHENTICATION_REQUIRED', 'message': ''}
            }, 401);
          }
          return jsonResponse({'data': profileJson()}, 200);
        }
        return jsonResponse({'data': []}, 200);
      }),
    );
    final auth = AuthController(api, database: NoopLocalDatabase());
    await tester.pumpWidget(NexoApp(authController: auth));
    await tester.pumpAndSettle();
    expect(find.text('بازی‌های در دسترس'), findsOneWidget);
    expect(find.text('هنوز بازی‌ای در دسترس نیست'), findsOneWidget);

    await tester.tap(find.byTooltip('پروفایل من'));
    await tester.pumpAndSettle();

    expect(profileReads, 2);
    expect(await api.token(), isNull);
    expect(find.text('ورود به بازی'), findsOneWidget);
    expect(find.byTooltip('پروفایل من'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}

class TestSecureStore extends SecureStore {
  final Map<String, String> values = {'nexo.device_id': deviceUuid};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async {
    values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }
}

class NoopLocalDatabase extends LocalDatabase {
  @override
  Future<void> clearCache() async {}
}
