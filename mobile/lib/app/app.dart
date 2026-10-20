import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import '../core/api/api_client.dart';
import '../core/auth/auth_controller.dart';
import '../features/auth/login_page.dart';
import '../features/auth/register_page.dart';
import '../features/home/home_page.dart';
import '../features/home/games_page.dart';
import '../features/home/information_pages.dart';
import '../features/missions/mission_hub_page.dart';
import '../features/profile/profile_page.dart';
import '../features/teams/lobby_page.dart';
import '../features/teams/team_page.dart';
import '../features/teams/join_team_page.dart';
import 'nexo_routes.dart';
import 'nexo_theme.dart';
import 'nexo_components.dart';

class NexoApp extends StatefulWidget {
  const NexoApp(
      {this.authController, this.showPreviewBanner = false, super.key});

  final AuthController? authController;
  final bool showPreviewBanner;

  @override
  State<NexoApp> createState() => _NexoAppState();
}

class _NexoAppState extends State<NexoApp> {
  final navigatorKey = GlobalKey<NavigatorState>();
  late final AuthController auth;
  bool? previousAuthentication;
  String? pendingInviteCode;

  @override
  void initState() {
    super.initState();
    auth = widget.authController ?? AuthController(ApiClient());
    auth.addListener(_authenticationChanged);
    auth.restore();
  }

  void _authenticationChanged() {
    if (auth.loading) {
      return;
    }
    final authenticated = auth.authenticated;
    final previous = previousAuthentication;
    previousAuthentication = authenticated;
    if (previous != null && previous != authenticated) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (authenticated && pendingInviteCode != null) {
          final code = pendingInviteCode!;
          pendingInviteCode = null;
          navigatorKey.currentState?.pushAndRemoveUntil(
            MaterialPageRoute<void>(builder: (_) => JoinTeamPage(auth: auth, initialInviteCode: code)),
            (_) => false,
          );
        } else {
          navigatorKey.currentState?.pushNamedAndRemoveUntil(
            authenticated ? NexoRoutes.home : NexoRoutes.login,
            (_) => false,
          );
        }
      });
    }
  }

  @override
  void dispose() {
    auth.removeListener(_authenticationChanged);
    auth.dispose();
    super.dispose();
  }

  Route<dynamic> _route(RouteSettings settings) {
    final name = settings.name;
    final inviteCode = name == null ? null : extractInviteCode(name);
    if (inviteCode != null) {
      if (auth.authenticated) {
        return MaterialPageRoute<void>(builder: (_) => JoinTeamPage(auth: auth, initialInviteCode: inviteCode), settings: settings);
      }
      pendingInviteCode = inviteCode;
    }
    if (auth.loading) {
      return MaterialPageRoute<void>(
          builder: (_) => const _LoadingPage(), settings: settings);
    }
    if (!auth.authenticated && name != NexoRoutes.register) {
      return MaterialPageRoute<void>(
          builder: (_) => LoginPage(auth: auth), settings: settings);
    }
    if (auth.authenticated &&
        (name == NexoRoutes.login || name == NexoRoutes.register)) {
      return MaterialPageRoute<void>(
          builder: (_) => HomePage(auth: auth), settings: settings);
    }
    return switch (name) {
      NexoRoutes.login => MaterialPageRoute<void>(
          builder: (_) => LoginPage(auth: auth), settings: settings),
      NexoRoutes.register => MaterialPageRoute<void>(
          builder: (_) => RegisterPage(auth: auth), settings: settings),
      NexoRoutes.home => MaterialPageRoute<void>(
          builder: (_) => HomePage(auth: auth), settings: settings),
      NexoRoutes.games => MaterialPageRoute<void>(
          builder: (_) => GamesPage(auth: auth), settings: settings),
      NexoRoutes.missions => MaterialPageRoute<void>(
          builder: (_) => MissionHubPage(auth: auth), settings: settings),
      NexoRoutes.scan => MaterialPageRoute<void>(
          builder: (_) => MissionHubPage(auth: auth, openScanner: true),
          settings: settings),
      NexoRoutes.tutorial => MaterialPageRoute<void>(
          builder: (_) => const TutorialPage(), settings: settings),
      NexoRoutes.notifications => MaterialPageRoute<void>(
          builder: (_) => const NotificationsPage(), settings: settings),
      NexoRoutes.profile => MaterialPageRoute<void>(
          builder: (_) => ProfilePage(auth: auth), settings: settings),
      NexoRoutes.teams => MaterialPageRoute<void>(
          builder: (_) => TeamPage(auth: auth), settings: settings),
      NexoRoutes.lobby => MaterialPageRoute<void>(
          builder: (_) => LobbyPage(auth: auth), settings: settings),
      _ => MaterialPageRoute<void>(
          builder: (_) =>
              auth.authenticated ? HomePage(auth: auth) : LoginPage(auth: auth),
          settings: settings),
    };
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: auth,
        builder: (context, _) => MaterialApp(
          navigatorKey: navigatorKey,
          onGenerateRoute: _route,
          home: auth.loading
              ? const _LoadingPage()
              : auth.authenticated
                  ? HomePage(auth: auth)
                  : LoginPage(auth: auth),
          title: 'NEXO',
          debugShowCheckedModeBanner: false,
          theme: NexoTheme.data,
          locale: const Locale('fa'),
          supportedLocales: const [Locale('fa'), Locale('en')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          builder: (context, child) => Directionality(
            textDirection: TextDirection.rtl,
            child: widget.showPreviewBanner
                ? Column(
                    children: [
                      Container(
                        width: double.infinity,
                        color: NexoTheme.cyan,
                        padding: const EdgeInsets.symmetric(vertical: 5),
                        child: const Text(
                          'پیش‌نمایش توسعه · تمام داده‌ها نمونه هستند',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                              color: NexoTheme.background,
                              fontSize: 11,
                              fontWeight: FontWeight.w700),
                        ),
                      ),
                      Expanded(child: child ?? const SizedBox.shrink()),
                    ],
                  )
                : child ?? const SizedBox.shrink(),
          ),
        ),
      );
}

class _LoadingPage extends StatelessWidget {
  const _LoadingPage();

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: DecoratedBox(
          decoration: BoxDecoration(
            gradient: RadialGradient(
              center: Alignment(0, -.35),
              radius: 1.1,
              colors: [Color(0xff24194A), NexoTheme.background],
            ),
          ),
          child: SafeArea(
            child: Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  NexoBrandMark(),
                  SizedBox(height: 34),
                  SizedBox.square(
                    dimension: 26,
                    child: CircularProgressIndicator(strokeWidth: 2.5),
                  ),
                  SizedBox(height: 14),
                  Text('در حال آماده‌سازی بازی…',
                      style: TextStyle(color: NexoTheme.muted)),
                ],
              ),
            ),
          ),
        ),
      );
}
