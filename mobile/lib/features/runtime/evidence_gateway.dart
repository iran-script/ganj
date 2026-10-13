import 'package:geolocator/geolocator.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';
import '../../core/offline/offline_identity.dart';
import 'evidence_device.dart';

class EvidenceGateway {
  EvidenceGateway([ApiClient? api]) : api = api ?? ApiClient();
  final ApiClient api;

  Future<void> start(String nodeId, String key) async {
    await api.post('/runtime/nodes/$nodeId/start',
        device: true, idempotent: true, idempotencyKey: key);
  }

  Future<Map<String, dynamic>> upload(
      String nodeId, String sessionId, CapturedEvidence evidence, String key,
      {required bool requireLocation}) async {
    final generation = OfflineIdentity.instance.generation;
    final owner = OfflineIdentity.instance.owner;
    if (requireLocation) {
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw const ApiError(code: 'LOCATION_INVALID', message: '');
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        throw const ApiError(code: 'LOCATION_INVALID', message: '');
      }
      final position = await Geolocator.getCurrentPosition(
          locationSettings: const LocationSettings(
              accuracy: LocationAccuracy.high,
              timeLimit: Duration(seconds: 25)));
      if (generation != OfflineIdentity.instance.generation) {
        throw const ApiError(code: 'AUTHENTICATION_REQUIRED', message: '');
      }
      await api.post('/locations',
          device: true,
          idempotent: true,
          expectedOwner: owner,
          idempotencyKey: const Uuid().v4(),
          body: {
            'session_id': sessionId,
            'lat': position.latitude,
            'lng': position.longitude,
            'accuracy': position.accuracy,
            'client_timestamp': position.timestamp.toUtc().toIso8601String(),
            'sequence': DateTime.now().microsecondsSinceEpoch,
          });
    }
    if (generation != OfflineIdentity.instance.generation) {
      throw const ApiError(code: 'AUTHENTICATION_REQUIRED', message: '');
    }
    return api.uploadEvidence(
        nodeId,
        evidence.bytes,
        evidence.filename,
        {
          'capture_source': evidence.source,
          'captured_at': evidence.capturedAt.toUtc().toIso8601String()
        },
        key);
  }
}
