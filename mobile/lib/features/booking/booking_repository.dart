import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';

class BookingRepository {
  BookingRepository(this.api);

  final ApiClient api;
  static const _uuid = Uuid();

  Future<Map<String, dynamic>> game(String gameId) => api.get('/games/$gameId');

  Future<Map<String, dynamic>> create({
    required String gameId,
    required String teamId,
    required String roomName,
    required String idempotencyKey,
  }) =>
      api.post('/games/$gameId/bookings',
          body: {'team_id': teamId, 'room_name': roomName},
          idempotent: true,
          idempotencyKey: idempotencyKey);

  Future<Map<String, dynamic>> checkout(String orderId) =>
      api.post('/payment-orders/$orderId/checkout');

  Future<List<Map<String, dynamic>>> payments() async {
    final response = await api.get('/me/payments');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList(growable: false);
  }

  String newIdempotencyKey() => _uuid.v4();
}
