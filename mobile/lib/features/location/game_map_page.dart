import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/config/app_config.dart';
import '../../core/errors/api_error.dart';
import '../runtime/gameplay_page.dart';
import '../runtime/runtime_repository.dart';

class GameMapPage extends StatefulWidget {
  const GameMapPage({
    required this.auth,
    required this.sessionId,
    this.gameName = 'بازی فعال',
    this.teamName,
    this.repository,
    super.key,
  });

  final AuthController auth;
  final String sessionId;
  final String gameName;
  final String? teamName;
  final RuntimeRepository? repository;

  @override
  State<GameMapPage> createState() => _GameMapPageState();
}

class _GameMapPageState extends State<GameMapPage> {
  final controller = MapController();
  late final RuntimeRepository repository =
      widget.repository ?? RuntimeRepository(widget.auth.api);
  late Future<Map<String, dynamic>> runtime =
      repository.projection(widget.sessionId);
  Position? position;
  String? locationMessage;
  bool locating = false;
  int tileFailures = 0;

  Future<void> _retryRuntime() async {
    setState(() => runtime = repository.projection(widget.sessionId));
    try {
      await runtime;
    } catch (_) {
      // FutureBuilder shows the retry state.
    }
  }

  Future<void> _locate({bool recenter = true}) async {
    if (locating) return;
    setState(() {
      locating = true;
      locationMessage = null;
    });
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        setState(() => locationMessage = 'مکان‌یابی گوشی خاموش است.');
        return;
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied) {
        setState(
            () => locationMessage = 'برای نمایش موقعیت، اجازهٔ مکان لازم است.');
        return;
      }
      if (permission == LocationPermission.deniedForever) {
        setState(() =>
            locationMessage = 'اجازهٔ مکان از تنظیمات گوشی مسدود شده است.');
        return;
      }
      final value = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 25),
        ),
      );
      if (!mounted) return;
      setState(() {
        position = value;
        locationMessage = null;
      });
      if (recenter) {
        controller.move(LatLng(value.latitude, value.longitude), 16);
      }
    } catch (_) {
      if (mounted) {
        setState(() => locationMessage =
            'دریافت موقعیت ممکن نشد. GPS و دسترسی را بررسی کنید.');
      }
    } finally {
      if (mounted) setState(() => locating = false);
    }
  }

  Future<void> _openLocationSettings() async {
    if (locationMessage?.contains('خاموش') == true) {
      await Geolocator.openLocationSettings();
    } else {
      await Geolocator.openAppSettings();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('نقشهٔ بازی'),
          actions: [
            IconButton(
              tooltip: 'موقعیت من',
              onPressed: () => _locate(),
              icon: const Icon(Icons.my_location),
            ),
          ],
        ),
        body: SafeArea(
          top: false,
          child: FutureBuilder<Map<String, dynamic>>(
            future: runtime,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done &&
                  !snapshot.hasData) {
                return const NexoLoading(message: 'دریافت مرحله‌های مجاز…');
              }
              if (snapshot.hasError && !snapshot.hasData) {
                return NexoErrorView(
                  message: snapshot.error is ApiError
                      ? (snapshot.error! as ApiError).userMessage
                      : ApiClient.isNetworkFailure(snapshot.error!)
                          ? 'برای دریافت نقاط مأموریت به اینترنت وصل شوید.'
                          : 'دریافت نقاط مجاز ممکن نشد.',
                  offline: ApiClient.isNetworkFailure(snapshot.error!),
                  onRetry: _retryRuntime,
                );
              }
              final data = snapshot.data!;
              final nodes = _missionPoints(data);
              final center = position == null
                  ? nodes.isEmpty
                      ? null
                      : nodes.first.point
                  : LatLng(position!.latitude, position!.longitude);
              if (center == null) {
                return _MapEmpty(onLocate: _locate, locating: locating);
              }
              final activeIds =
                  (data['active_node_ids'] as List? ?? const []).toSet();
              final next = nodes
                  .where((node) => activeIds.contains(node.id))
                  .firstOrNull;
              return Stack(
                children: [
                  FlutterMap(
                    mapController: controller,
                    options: MapOptions(initialCenter: center, initialZoom: 15),
                    children: [
                      TileLayer(
                        urlTemplate: AppConfig.mapTileUrl,
                        userAgentPackageName: 'com.nexo.nexo_player',
                        maxZoom: 19,
                        errorTileCallback: (tile, error, stackTrace) {
                          if (mounted && tileFailures == 0) {
                            setState(() => tileFailures++);
                          }
                        },
                      ),
                      MarkerLayer(
                        markers: [
                          for (final node in nodes)
                            Marker(
                              point: node.point,
                              width: 54,
                              height: 62,
                              child: _MissionMarker(
                                active: node.id == next?.id,
                                onTap: () => _showMission(node,
                                    isNext: node.id == next?.id),
                              ),
                            ),
                          if (position != null)
                            Marker(
                              point: LatLng(
                                  position!.latitude, position!.longitude),
                              width: 34,
                              height: 34,
                              child: const _PlayerMarker(),
                            ),
                        ],
                      ),
                      RichAttributionWidget(
                        alignment: AttributionAlignment.bottomRight,
                        attributions: [
                          TextSourceAttribution(
                            AppConfig.mapTileAttribution,
                            onTap: () => launchUrl(
                                Uri.parse(AppConfig.mapTileAttributionUrl)),
                          ),
                        ],
                      ),
                    ],
                  ),
                  if (next != null)
                    Positioned(
                      top: 12,
                      left: 16,
                      right: 16,
                      child: _NextMissionBanner(
                        node: next,
                        onTap: () => _showMission(next, isNext: true),
                      ),
                    ),
                  if (locationMessage != null)
                    Positioned(
                      left: 16,
                      right: 16,
                      bottom: 20,
                      child: _LocationNotice(
                        message: locationMessage!,
                        onSettings: _openLocationSettings,
                      ),
                    ),
                  if (tileFailures > 0)
                    Positioned(
                      left: 16,
                      right: 16,
                      top: next == null ? 12 : 80,
                      child: const _OfflineTilesNotice(),
                    ),
                  Positioned(
                    right: 16,
                    bottom: locationMessage == null ? 56 : 120,
                    child: FloatingActionButton.small(
                      heroTag: 'nexo-map-recenter',
                      onPressed: () => _locate(),
                      backgroundColor: NexoTheme.surface,
                      foregroundColor: NexoTheme.cyan,
                      child: locating
                          ? const SizedBox.square(
                              dimension: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.my_location),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
        bottomNavigationBar: const NexoBottomNavigation(selectedIndex: 3),
      );

  List<_MissionPoint> _missionPoints(Map<String, dynamic> projection) {
    final rawNodes = (projection['nodes'] as List? ?? const [])
        .whereType<Map<String, dynamic>>();
    final result = <_MissionPoint>[];
    for (final node in rawNodes) {
      final type = (node['node_type'] as String? ?? '').toUpperCase();
      if (!type.contains('GPS') && type != 'CHECKPOINT') continue;
      final config = node['config'] as Map<String, dynamic>? ?? const {};
      final checkpoint = config['checkpoint'] as Map?;
      final geometry = checkpoint?['geom'] as Map?;
      final coordinates = geometry?['coordinates'] as List?;
      final isPoint = geometry?['type'] == 'Point';
      final latitude = _asDouble(config['latitude']) ??
          _asDouble((config['gps'] as Map?)?['latitude']) ??
          (isPoint && coordinates != null && coordinates.length >= 2
              ? _asDouble(coordinates[1])
              : null);
      final longitude = _asDouble(config['longitude']) ??
          _asDouble((config['gps'] as Map?)?['longitude']) ??
          (isPoint && coordinates != null && coordinates.length >= 2
              ? _asDouble(coordinates[0])
              : null);
      if (latitude == null ||
          longitude == null ||
          latitude < -90 ||
          latitude > 90 ||
          longitude < -180 ||
          longitude > 180) {
        continue;
      }
      final title = node['title'] as String? ??
          checkpoint?['name'] as String? ??
          config['title'] as String? ??
          'نقطهٔ مأموریت';
      result.add(_MissionPoint(
        id: node['id'] as String? ?? '',
        title: title,
        status: node['status'] as String? ?? 'LOCKED',
        point: LatLng(latitude, longitude),
      ));
    }
    return result;
  }

  double? _asDouble(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value');

  void _showMission(_MissionPoint node, {required bool isNext}) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(children: [
                const Icon(Icons.flag_circle, color: NexoTheme.cyan),
                const SizedBox(width: 12),
                Expanded(
                    child: Text(node.title,
                        style: Theme.of(context).textTheme.titleLarge)),
              ]),
              const SizedBox(height: 10),
              Text(isNext ? 'مرحلهٔ بعدی شما' : _statusLabel(node.status),
                  style: const TextStyle(color: NexoTheme.muted)),
              const SizedBox(height: 18),
              FilledButton.icon(
                onPressed: () {
                  Navigator.of(context).pop();
                  Navigator.of(this.context).push(MaterialPageRoute<void>(
                    builder: (_) => GameplayPage(
                      sessionId: widget.sessionId,
                      gameName: widget.gameName,
                      teamName: widget.teamName,
                    ),
                  ));
                },
                icon: const Icon(Icons.play_arrow),
                label: const Text('ادامهٔ مأموریت'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _statusLabel(String value) => switch (value.toUpperCase()) {
        'AVAILABLE' => 'آمادهٔ شروع',
        'IN_PROGRESS' => 'در حال انجام',
        'COMPLETED' => 'تکمیل‌شده',
        _ => 'نقطهٔ مجاز بازی',
      };
}

class _MissionPoint {
  const _MissionPoint(
      {required this.id,
      required this.title,
      required this.status,
      required this.point});

  final String id;
  final String title;
  final String status;
  final LatLng point;
}

class _MissionMarker extends StatelessWidget {
  const _MissionMarker({required this.active, required this.onTap});

  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        label: active ? 'مرحلهٔ بعدی' : 'نقطهٔ مأموریت',
        child: GestureDetector(
          onTap: onTap,
          child: Icon(
            active ? Icons.location_on : Icons.location_on_outlined,
            size: active ? 48 : 42,
            color: active ? NexoTheme.primary : NexoTheme.cyan,
            shadows: const [
              Shadow(
                  color: Color(0x66000000), blurRadius: 8, offset: Offset(0, 2))
            ],
          ),
        ),
      );
}

class _PlayerMarker extends StatelessWidget {
  const _PlayerMarker();

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(
          color: NexoTheme.cyan.withValues(alpha: .2),
          shape: BoxShape.circle,
          border: Border.all(color: NexoTheme.cyan, width: 2),
        ),
        child: const Center(
            child: Icon(Icons.circle, color: NexoTheme.cyan, size: 12)),
      );
}

class _NextMissionBanner extends StatelessWidget {
  const _NextMissionBanner({required this.node, required this.onTap});

  final _MissionPoint node;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Card(
        margin: EdgeInsets.zero,
        child: ListTile(
          onTap: onTap,
          leading: const Icon(Icons.flag, color: NexoTheme.primary),
          title: const Text('مرحلهٔ بعدی'),
          subtitle:
              Text(node.title, maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: const Icon(Icons.chevron_left),
        ),
      );
}

class _LocationNotice extends StatelessWidget {
  const _LocationNotice({required this.message, required this.onSettings});

  final String message;
  final VoidCallback onSettings;

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: const Icon(Icons.location_off_outlined,
              color: NexoTheme.amberSoft),
          title: Text(message, style: const TextStyle(fontSize: 13)),
          trailing:
              TextButton(onPressed: onSettings, child: const Text('تنظیمات')),
        ),
      );
}

class _OfflineTilesNotice extends StatelessWidget {
  const _OfflineTilesNotice();

  @override
  Widget build(BuildContext context) => const NexoNotice(
        'نقشهٔ زمینه بارگیری نشد. اتصال اینترنت یا سرویس نقشه را بررسی کنید؛ نقاط مجاز همچنان در دسترس‌اند.',
        icon: Icons.cloud_off_outlined,
        color: NexoTheme.amberSoft,
      );
}

class _MapEmpty extends StatelessWidget {
  const _MapEmpty({required this.onLocate, required this.locating});

  final Future<void> Function({bool recenter}) onLocate;
  final bool locating;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.map_outlined, size: 56, color: NexoTheme.cyan),
              const SizedBox(height: 16),
              Text('هنوز نقطهٔ مکانی مجازی برای این بازی دریافت نشده است.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 8),
              const Text(
                  'موقعیت گوشی را روشن کنید یا بعد از دریافت مرحلهٔ مکانی دوباره به نقشه برگردید.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: NexoTheme.muted, height: 1.5)),
              const SizedBox(height: 18),
              FilledButton.icon(
                onPressed: locating ? null : () => onLocate(),
                icon: locating
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.my_location),
                label: const Text('نمایش موقعیت من'),
              ),
            ],
          ),
        ),
      );
}
