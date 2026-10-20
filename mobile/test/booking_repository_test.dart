import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/booking/booking_repository.dart';

void main() {
  test('booking creation sends the team and stable idempotency key', () async {
    late http.Request sent;
    final api = ApiClient(
      store: BookingTestStore(),
      client: MockClient((request) async {
        sent = request;
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'booking-id',
              'status': 'ROOM_CREATED',
              'room': {'id': 'room-id'},
            },
          }),
          201,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final result = await BookingRepository(api).create(
      gameId: 'game-id',
      teamId: 'team-id',
      roomName: 'NEXO team',
      idempotencyKey: 'stable-booking-key',
    );

    expect(sent.method, 'POST');
    expect(sent.url.path, endsWith('/games/game-id/bookings'));
    expect(sent.headers['Authorization'], 'Bearer test-access-token');
    expect(sent.headers['Idempotency-Key'], 'stable-booking-key');
    expect(jsonDecode(sent.body),
        {'team_id': 'team-id', 'room_name': 'NEXO team'});
    expect(result['status'], 'ROOM_CREATED');
    expect((result['room'] as Map<String, dynamic>)['id'], 'room-id');
  });

  test('payment history parses the canonical data list', () async {
    final api = ApiClient(
      store: BookingTestStore(),
      client: MockClient((request) async {
        expect(request.url.path, endsWith('/me/payments'));
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'order-id',
                'amount': 5000,
                'currency': 'IRR',
                'status': 'PENDING',
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final payments = await BookingRepository(api).payments();
    expect(payments, hasLength(1));
    expect(payments.single['id'], 'order-id');
    expect(payments.single['status'], 'PENDING');
  });

  test('unconfigured payment provider remains a surfaced API error', () async {
    final api = ApiClient(
      store: BookingTestStore(),
      client: MockClient((request) async => http.Response(
            '{"error":{"code":"PAYMENT_PROVIDER_UNAVAILABLE","message":""}}',
            503,
            headers: {'content-type': 'application/json'},
          )),
    );

    await expectLater(
      BookingRepository(api).checkout('order-id'),
      throwsA(isA<ApiError>().having(
        (error) => error.code,
        'code',
        'PAYMENT_PROVIDER_UNAVAILABLE',
      )),
    );
  });
}

class BookingTestStore extends SecureStore {
  @override
  Future<String?> read(String key) async => key == 'nexo.device_id'
      ? '00000000-0000-4000-8000-000000000001'
      : 'test-access-token';
}
