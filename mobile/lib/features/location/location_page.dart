import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';
import '../../core/offline/offline_identity.dart';
import '../../core/offline/outbox_repository.dart';

abstract interface class LocationGateway {
  Future<bool> serviceEnabled();
  Future<LocationPermission> checkPermission();
  Future<LocationPermission> requestPermission();
  Future<Position> currentPosition();
}

class GeolocatorGateway implements LocationGateway {
  const GeolocatorGateway();

  @override
  Future<bool> serviceEnabled() => Geolocator.isLocationServiceEnabled();

  @override
  Future<LocationPermission> checkPermission() => Geolocator.checkPermission();

  @override
  Future<LocationPermission> requestPermission() =>
      Geolocator.requestPermission();

  @override
  Future<Position> currentPosition() => Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 25)));
}

class LocationPage extends StatefulWidget {
  const LocationPage({
    required this.sessionId,
    required this.onLocation,
    this.api,
    this.gateway,
    this.outbox,
    super.key,
  });

  final String sessionId;
  final Future<void> Function(Map<String, dynamic>) onLocation;
  final ApiClient? api;
  final LocationGateway? gateway;
  final OutboxRepository? outbox;

  @override
  State<LocationPage> createState() => _LocationPageState();
}

class _LocationPageState extends State<LocationPage> {
  late final ApiClient api = widget.api ?? ApiClient();
  late final LocationGateway gateway =
      widget.gateway ?? const GeolocatorGateway();
  late final OutboxRepository outbox = widget.outbox ?? OutboxRepository();
  String? message;
  bool loading = false;

  Future<void> locate() async {
    if (loading) return;
    final generation = OfflineIdentity.instance.generation;
    final owner = OfflineIdentity.instance.owner;
    setState(() {
      loading = true;
      message = null;
    });
    try {
      if (!await gateway.serviceEnabled()) {
        throw const ApiError(
            code: 'LOCATION_INVALID', message: 'Location is disabled.');
      }
      var permission = await gateway.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await gateway.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        throw const ApiError(
            code: 'LOCATION_INVALID', message: 'Location permission denied.');
      }
      final position = await gateway.currentPosition();
      if (owner == null || generation != OfflineIdentity.instance.generation) {
        throw const ApiError(
            code: 'AUTHENTICATION_REQUIRED', message: 'Identity changed.');
      }

      Map<String, dynamic>? policy;
      try {
        policy = await api.get(
            '/game-sessions/${widget.sessionId}/players/me/location-policy',
            device: true);
      } catch (failure) {
        if (!ApiClient.isNetworkFailure(failure)) rethrow;
      }
      if (policy?['active'] == false) {
        throw const ApiError(code: 'ACTION_NOT_ALLOWED', message: '');
      }
      final minAccuracy = (policy?['min_accuracy'] as num?)?.toDouble();
      if (minAccuracy != null && position.accuracy > minAccuracy) {
        throw const ApiError(code: 'LOCATION_INVALID', message: '');
      }

      final key = const Uuid().v4();
      final sample = {
        'session_id': widget.sessionId,
        'lat': position.latitude,
        'lng': position.longitude,
        'accuracy': position.accuracy,
        'client_timestamp': position.timestamp.toUtc().toIso8601String(),
        'sequence': DateTime.now().microsecondsSinceEpoch
      };
      try {
        await api.post('/locations',
            device: true,
            idempotent: true,
            idempotencyKey: key,
            expectedOwner: owner,
            body: sample);
      } catch (failure) {
        if (generation != OfflineIdentity.instance.generation ||
            !ApiClient.isNetworkFailure(failure)) {
          rethrow;
        }
        await outbox.enqueue(
            clientActionId: key,
            actionType: 'gps_sample',
            sessionId: widget.sessionId,
            payload: sample);
        if (mounted) {
          setState(() => message =
              'موقعیت ذخیره شد و پس از اتصال ارسال می‌شود؛ هنوز تأیید نشده است.');
        }
        return;
      }
      await widget.onLocation({'acknowledged': true});
      if (mounted) {
        setState(() =>
            message = 'موقعیت ارسال شد؛ نتیجه مأموریت توسط سرور بررسی می‌شود.');
      }
    } on TimeoutException {
      if (mounted) {
        setState(() => message =
            'دریافت موقعیت بیش از حد طول کشید. فضای بازتر را امتحان کنید.');
      }
    } on ApiError catch (exception) {
      if (mounted) setState(() => message = exception.userMessage);
    } catch (_) {
      if (mounted) {
        setState(() => message = 'دریافت موقعیت ممکن نشد؛ دوباره تلاش کنید.');
      }
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('موقعیت')),
      body: SafeArea(
          child: Center(
              child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 480),
                      child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const Icon(Icons.location_on, size: 64),
                            const SizedBox(height: 12),
                            const Text('موقعیت شما با سیاست نشست بررسی می‌شود.',
                                textAlign: TextAlign.center),
                            if (message != null) ...[
                              const SizedBox(height: 12),
                              Text(message!, textAlign: TextAlign.center)
                            ],
                            const SizedBox(height: 20),
                            FilledButton(
                                onPressed: loading ? null : locate,
                                child: loading
                                    ? const SizedBox.square(
                                        dimension: 20,
                                        child: CircularProgressIndicator(
                                            strokeWidth: 2))
                                    : const Text('ارسال موقعیت'))
                          ]))))));
}
