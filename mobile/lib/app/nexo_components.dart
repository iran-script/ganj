import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'nexo_theme.dart';
import 'nexo_routes.dart';

abstract final class NexoSpace {
  static const x1 = 4.0;
  static const x2 = 8.0;
  static const x3 = 12.0;
  static const x4 = 16.0;
  static const x6 = 24.0;
  static const x8 = 32.0;
}

class NexoLoading extends StatelessWidget {
  const NexoLoading({this.message = 'کمی صبر کنید…', super.key});

  final String message;

  @override
  Widget build(BuildContext context) => Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const SizedBox.square(
            dimension: 34,
            child: CircularProgressIndicator(strokeWidth: 3),
          ),
          const SizedBox(height: NexoSpace.x4),
          Text(message, textAlign: TextAlign.center),
        ]),
      );
}

class NexoSkeleton extends StatefulWidget {
  const NexoSkeleton({
    this.width,
    this.height = 16,
    this.radius = NexoTheme.radiusSmall,
    super.key,
  });

  final double? width;
  final double height;
  final double radius;

  @override
  State<NexoSkeleton> createState() => _NexoSkeletonState();
}

class _NexoSkeletonState extends State<NexoSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController animation = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      animation.stop();
    } else if (!animation.isAnimating) {
      animation.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    animation.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: animation,
        builder: (context, _) => Container(
          width: widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            color: Color.lerp(
              NexoTheme.surfaceRaised,
              NexoTheme.border,
              MediaQuery.disableAnimationsOf(context)
                  ? .35
                  : .2 + animation.value * .3,
            ),
            borderRadius: BorderRadius.circular(widget.radius),
          ),
        ),
      );
}

class NexoErrorView extends StatelessWidget {
  const NexoErrorView({
    required this.message,
    required this.onRetry,
    this.offline = false,
    super.key,
  });

  final String message;
  final VoidCallback onRetry;
  final bool offline;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(NexoSpace.x6),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(offline ? Icons.cloud_off_outlined : Icons.error_outline,
                color: NexoTheme.danger, size: 42),
            const SizedBox(height: NexoSpace.x3),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: NexoSpace.x4),
            FilledButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('تلاش دوباره'),
            ),
          ]),
        ),
      );
}

class NexoEmptyView extends StatelessWidget {
  const NexoEmptyView({
    required this.title,
    required this.message,
    this.icon = Icons.explore_outlined,
    super.key,
  });

  final String title;
  final String message;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(NexoSpace.x6),
          child: Column(children: [
            Icon(icon, size: 42, color: NexoTheme.amber),
            const SizedBox(height: NexoSpace.x3),
            Text(title,
                style: Theme.of(context).textTheme.titleLarge,
                textAlign: TextAlign.center),
            const SizedBox(height: NexoSpace.x2),
            Text(message, textAlign: TextAlign.center),
          ]),
        ),
      );
}

class NexoOfflineBanner extends StatelessWidget {
  const NexoOfflineBanner({super.key});

  @override
  Widget build(BuildContext context) => const NexoNotice(
        'اتصال اینترنت برقرار نیست. پس از اتصال دوباره تلاش کنید.',
        icon: Icons.cloud_off_outlined,
        color: NexoTheme.danger,
      );
}

class NexoBrandMark extends StatelessWidget {
  const NexoBrandMark({this.compact = false, super.key});

  final bool compact;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: compact ? 36 : 48,
            height: compact ? 36 : 48,
            decoration: BoxDecoration(
              color: NexoTheme.amber,
              borderRadius: BorderRadius.circular(NexoTheme.radiusSmall),
            ),
            child: Icon(Icons.explore,
                color: NexoTheme.background, size: compact ? 22 : 28),
          ),
          const SizedBox(width: NexoSpace.x3),
          Text('NEXO',
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  color: NexoTheme.foreground,
                  fontWeight: FontWeight.w900,
                  letterSpacing: 1.2)),
        ],
      );
}

class NexoPageBody extends StatelessWidget {
  const NexoPageBody({required this.child, super.key});

  final Widget child;

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
            child: child,
          ),
        ),
      );
}

class NexoBottomNavigation extends StatelessWidget {
  const NexoBottomNavigation({required this.selectedIndex, super.key});

  final int selectedIndex;

  static const _destinations = <(String, IconData, String)>[
    ('خانه', Icons.home_outlined, NexoRoutes.home),
    ('بازی‌ها', Icons.explore_outlined, NexoRoutes.games),
    ('مدیریت تیم', Icons.groups_2_outlined, NexoRoutes.teams),
    ('مأموریت', Icons.flag_outlined, NexoRoutes.missions),
    ('پروفایل', Icons.person_outline, NexoRoutes.profile),
  ];

  @override
  Widget build(BuildContext context) => SafeArea(
        top: false,
        child: NavigationBar(
          selectedIndex: selectedIndex,
          height: 76,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          onDestinationSelected: (index) {
            final route = _destinations[index].$3;
            if (index != selectedIndex) {
              HapticFeedback.selectionClick();
              Navigator.of(context)
                  .pushNamedAndRemoveUntil(route, (_) => false);
            }
          },
          destinations: [
            for (final destination in _destinations)
              NavigationDestination(
                icon: Icon(destination.$2),
                label: destination.$1,
              ),
          ],
        ),
      );
}

class NexoCityHeader extends StatelessWidget {
  const NexoCityHeader(
      {required this.eyebrow, required this.title, this.body, super.key});

  final String eyebrow;
  final String title;
  final String? body;

  @override
  Widget build(BuildContext context) => Container(
        clipBehavior: Clip.antiAlias,
        margin: const EdgeInsets.only(bottom: NexoSpace.x6),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            begin: AlignmentDirectional.topStart,
            end: AlignmentDirectional.bottomEnd,
            colors: [Color(0xff30205E), Color(0xff100D28)],
          ),
          borderRadius: BorderRadius.circular(NexoTheme.radiusLarge),
          border: Border.all(color: NexoTheme.border),
        ),
        child: Stack(children: [
          const Positioned.fill(child: CustomPaint(painter: _CityPainter())),
          Padding(
            padding: const EdgeInsets.all(NexoSpace.x6),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(eyebrow,
                    style: const TextStyle(
                        color: NexoTheme.amberSoft,
                        fontWeight: FontWeight.w700)),
                const SizedBox(height: NexoSpace.x2),
                Text(title, style: Theme.of(context).textTheme.headlineSmall),
                if (body != null) ...[
                  const SizedBox(height: NexoSpace.x2),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 340),
                    child: Text(body!,
                        style: const TextStyle(
                            color: NexoTheme.muted, height: 1.6)),
                  ),
                ],
              ],
            ),
          ),
        ]),
      );
}

class NexoCityArtwork extends StatelessWidget {
  const NexoCityArtwork({this.height = 112, super.key});

  final double height;

  @override
  Widget build(BuildContext context) => Container(
        height: height,
        clipBehavior: Clip.antiAlias,
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: AlignmentDirectional.topStart,
            end: AlignmentDirectional.bottomEnd,
            colors: [Color(0xff30205E), Color(0xff100D28)],
          ),
        ),
        child: const Stack(children: [
          Positioned.fill(child: CustomPaint(painter: _CityPainter())),
          PositionedDirectional(
            top: 16,
            end: 18,
            child:
                Icon(Icons.explore_outlined, color: NexoTheme.amber, size: 34),
          ),
        ]),
      );
}

class _CityPainter extends CustomPainter {
  const _CityPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final skyline = Paint()
      ..color = const Color(0xff07141a).withValues(alpha: .88);
    final glow = Paint()..color = NexoTheme.amber.withValues(alpha: .68);
    final buildings = <Rect>[
      Rect.fromLTWH(size.width * .60, size.height * .51, size.width * .11,
          size.height * .49),
      Rect.fromLTWH(size.width * .73, size.height * .31, size.width * .12,
          size.height * .69),
      Rect.fromLTWH(size.width * .88, size.height * .45, size.width * .12,
          size.height * .55),
      Rect.fromLTWH(size.width * .52, size.height * .68, size.width * .07,
          size.height * .32),
    ];
    for (final building in buildings) {
      canvas.drawRRect(
          RRect.fromRectAndRadius(building, const Radius.circular(5)), skyline);
      for (var row = 0; row < 3; row++) {
        for (var col = 0; col < 2; col++) {
          final x = building.left + 8 + col * 15;
          final y = building.top + 12 + row * 17;
          if (x < building.right - 4 && y < building.bottom - 4) {
            canvas.drawCircle(Offset(x, y), 1.5, glow);
          }
        }
      }
    }
  }

  @override
  bool shouldRepaint(covariant _CityPainter oldDelegate) => false;
}

class NexoPageScaffold extends StatelessWidget {
  const NexoPageScaffold(
      {required this.title,
      required this.selectedIndex,
      required this.body,
      this.actions = const [],
      super.key});

  final String title;
  final int selectedIndex;
  final Widget body;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(title), actions: actions),
        body: SafeArea(top: false, child: body),
        bottomNavigationBar: NexoBottomNavigation(selectedIndex: selectedIndex),
      );
}
