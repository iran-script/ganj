import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';
import '../../core/offline/outbox_repository.dart';

class LocationPage extends StatefulWidget {
  const LocationPage({required this.sessionId, required this.onLocation, super.key});

  final String sessionId;
  final Future<void> Function(Map<String, dynamic>) onLocation;

  @override
  State<LocationPage> createState() => _LocationPageState();
}

class _LocationPageState extends State<LocationPage> {
  final outbox = OutboxRepository();
  String? message;
  bool loading = false;

  Future<void> locate() async {
    setState(() { loading = true; message = null; });
    try {
      if (!await Geolocator.isLocationServiceEnabled()) throw const ApiError(code: 'LOCATION_INVALID', message: 'Location is disabled.');
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) throw const ApiError(code: 'LOCATION_INVALID', message: 'Location permission denied.');
      final position = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high));
      final sample = {'session_id': widget.sessionId, 'lat': position.latitude, 'lng': position.longitude, 'accuracy': position.accuracy,
        'client_timestamp': DateTime.now().toUtc().toIso8601String(), 'sequence': DateTime.now().microsecondsSinceEpoch};
      try {
        await ApiClient().post('/locations', device: true, idempotent: true, body: sample);
      } catch (error) {
        if (!ApiClient.isNetworkFailure(error)) rethrow;
        final action = await outbox.enqueue(actionType: 'gps_sample', sessionId: widget.sessionId, payload: sample);
        if (mounted) setState(() => message = 'موقعیت برای همگام‌سازی ذخیره شد (${action.clientActionId.substring(0, 8)}).');
        return;
      }
      await widget.onLocation({'acknowledged': true});
      if (mounted) setState(() => message = 'موقعیت ارسال شد؛ نتیجه توسط سرور بررسی می‌شود.');
    } on ApiError catch (exception) {
      if (mounted) setState(() => message = exception.userMessage);
    } catch (_) {
      if (mounted) setState(() => message = 'دریافت موقعیت ممکن نیست.');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: const Text('موقعیت')), body: Center(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.location_on, size: 64), const SizedBox(height: 12), const Text('موقعیت شما توسط سرور بررسی می‌شود.'), if (message != null) ...[const SizedBox(height: 12), Text(message!)], const SizedBox(height: 20), FilledButton(onPressed: loading ? null : locate, child: loading ? const CircularProgressIndicator() : const Text('ارسال موقعیت'))]))));
}
