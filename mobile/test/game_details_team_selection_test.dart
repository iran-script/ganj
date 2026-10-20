import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/nexo_theme.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/booking/game_details_page.dart';

const _userId = '3a4f2cab-7773-4b1a-9de1-63309ed034d1';
const _teamId = 'ce7148b8-2a71-4fc5-b13e-0e95c7ef1921';
const _ineligibleTeamId = 'ec7148b8-2a71-4fc5-b13e-0e95c7ef1921';
const _memberTeamId = 'dc7148b8-2a71-4fc5-b13e-0e95c7ef1921';

Map<String, dynamic> _teamJson({
  int members = 4,
  String? captainId,
  String id = _teamId,
}) =>
    {
      'id': id,
      'name': 'کاوشگران',
      'status': 'ACTIVE',
      'captain_user_id': captainId ?? _userId,
      'captain': {'id': captainId ?? _userId, 'name': 'آرمان'},
      'members': [
        for (var index = 0; index < members; index++)
          {
            'user_id': index == 0 ? (captainId ?? _userId) : 'member-$index',
            'name': index == 0 ? 'آرمان' : 'بازیکن $index',
            'status': 'ACTIVE',
          }
      ],
      'eligible_for_game': members >= 4 && members <= 8,
    };

http.Response _response(Object data, [int status = 200]) => http.Response(
      jsonEncode({'data': data}),
      status,
      headers: const {'content-type': 'application/json'},
    );

class _Store extends SecureStore {
  @override
  Future<String?> read(String key) async => key == 'nexo.device_id'
      ? '4b48025a-2503-4e1c-92b6-e2600db66871'
      : key == 'nexo.access_token'
          ? 'test-token'
          : null;
}

class _Database extends LocalDatabase {
  @override
  Future<void> clearCache() async {}
}

Widget _host(Widget child) => MaterialApp(
      theme: NexoTheme.data,
      locale: const Locale('fa'),
      home: Directionality(textDirection: TextDirection.rtl, child: child),
    );

AuthController _auth(ApiClient api) => AuthController(
      api,
      database: _Database(),
      identity: OfflineIdentity(),
    )..user = {'id': _userId, 'name': 'آرمان'};

void main() {
  testWidgets(
      'empty team list opens independent management and refreshes on return',
      (tester) async {
    var teamCreated = false;
    var teamLists = 0;
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/games/game-1') {
          return _response({'id': 'game-1', 'name': 'بازی آزمایشی'});
        }
        if (request.url.path == '/api/v1/teams' && request.method == 'GET') {
          teamLists++;
          return _response(teamCreated ? [_teamJson()] : []);
        }
        if (request.url.path == '/api/v1/teams' && request.method == 'POST') {
          teamCreated = true;
          return _response(_teamJson(), 201);
        }
        if (request.url.path.endsWith('/invites') && request.method == 'GET') {
          return _response([]);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = _auth(api);

    await tester.pumpWidget(_host(GameDetailsPage(
      auth: controller,
      game: const {'id': 'game-1'},
    )));
    await tester.pumpAndSettle();
    expect(
      find.text(
          'هنوز تیمی نساختی. ابتدا از بخش مدیریت تیم، تیم خودت رو تشکیل بده.'),
      findsOneWidget,
    );
    expect(find.text('رفتن به مدیریت تیم'), findsOneWidget);

    await tester.tap(find.text('رفتن به مدیریت تیم'));
    await tester.pumpAndSettle();
    expect(find.text('ساخت تیم'), findsOneWidget);
    await tester.tap(find.text('ساخت تیم'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'کاوشگران');
    await tester.tap(find.text('ادامه'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('بازگشت'));
    await tester.pumpAndSettle();

    expect(teamLists, greaterThanOrEqualTo(3));
    expect(find.text('کاوشگران'), findsOneWidget);
    expect(find.text('انتخاب تیم'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  testWidgets(
      'booking starts only after choosing an eligible team and never checks out automatically',
      (tester) async {
    var bookingCalls = 0;
    var checkoutCalls = 0;
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/games/game-1') {
          return _response({'id': 'game-1', 'name': 'بازی آزمایشی'});
        }
        if (request.url.path == '/api/v1/teams') {
          return _response([
            _teamJson(members: 3),
            _teamJson(members: 4),
          ]);
        }
        if (request.url.path == '/api/v1/games/game-1/bookings') {
          bookingCalls++;
          expect(request.method, 'POST');
          expect(jsonDecode(request.body)['team_id'], _teamId);
          return _response(
              {'id': 'booking-1', 'status': 'AWAITING_PAYMENT'}, 201);
        }
        if (request.url.path.endsWith('/checkout')) {
          checkoutCalls++;
          return _response({'status': 'unexpected'});
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = _auth(api);

    await tester.pumpWidget(_host(GameDetailsPage(
      auth: controller,
      game: const {'id': 'game-1'},
    )));
    await tester.pumpAndSettle();
    expect(find.text('کاوشگران'), findsNWidgets(2));
    expect(find.textContaining('عضو فعال'), findsOneWidget);
    expect(bookingCalls, 0);

    await tester.tap(find.text('کاوشگران').first);
    await tester.pumpAndSettle();
    expect(find.text('تیم آماده رزرو است.'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'ادامه به رزرو'))
          .onPressed,
      isNotNull,
    );
    final bookingButton = find.widgetWithText(FilledButton, 'ادامه به رزرو');
    await tester.ensureVisible(bookingButton);
    await tester.tap(bookingButton);
    await tester.pumpAndSettle();

    expect(bookingCalls, 1);
    expect(checkoutCalls, 0);
    expect(find.textContaining('پرداخت خودکار شروع نشده'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  testWidgets('ineligible team and non-captain receive specific reasons',
      (tester) async {
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/games/game-1') {
          return _response({'id': 'game-1', 'name': 'بازی آزمایشی'});
        }
        if (request.url.path == '/api/v1/teams') {
          return _response([
            _teamJson(members: 3, id: _ineligibleTeamId),
            _teamJson(
              members: 4,
              captainId: 'another-player',
              id: _memberTeamId,
            ),
          ]);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = _auth(api);

    await tester.pumpWidget(_host(GameDetailsPage(
      auth: controller,
      game: const {'id': 'game-1'},
    )));
    await tester.pumpAndSettle();
    expect(
        find.textContaining('عضو فعال؛ ۱ عضو دیگر لازم است'), findsOneWidget);
    await tester.tap(find.text('کاوشگران').first);
    await tester.pumpAndSettle();
    expect(find.text('فقط کاپیتان تیم مجوز ثبت رزرو دارد.'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });
}
