import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import '../core/api/api_client.dart';
import '../core/auth/auth_controller.dart';
import '../core/config/app_config.dart';
import '../features/auth/login_page.dart';
import '../features/auth/register_page.dart';
import '../features/auth/server_settings_page.dart';
import '../features/home/home_page.dart';
import '../features/home/games_page.dart';
import '../features/home/information_pages.dart';
import '../features/profile/profile_page.dart';
import '../features/teams/lobby_page.dart';
import '../features/teams/team_page.dart';
import 'nexo_routes.dart';
import 'nexo_theme.dart';

class NexoApp extends StatefulWidget {
  const NexoApp({this.authController, this.serverConfigured, super.key});

  final AuthController? authController;
  final bool? serverConfigured;

  @override
  State<NexoApp> createState() => _NexoAppState();
}

class _NexoAppState extends State<NexoApp> {
  final navigatorKey = GlobalKey<NavigatorState>();
  late final AuthController auth;
  late bool serverConfigured;
  bool? previousAuthentication;

  @override
  void initState() {
    super.initState();
    auth = widget.authController ?? AuthController(ApiClient());
    serverConfigured = widget.serverConfigured ??
        (widget.authController != null || AppConfig.hasConfiguredApiBaseUrl);
    auth.addListener(_authenticationChanged);
    if (serverConfigured) {
      auth.restore();
    } else {
      auth.loading = false;
    }
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
        navigatorKey.currentState?.pushNamedAndRemoveUntil(
          authenticated ? NexoRoutes.home : NexoRoutes.login,
          (_) => false,
        );
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
              : !serverConfigured
                  ? ServerSettingsPage(auth: auth, onSaved: _serverSaved)
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
            child: child ?? const SizedBox.shrink(),
          ),
        ),
      );

  Future<void> _serverSaved() async {
    if (!mounted) return;
    setState(() => serverConfigured = true);
    await auth.restore();
    if (!mounted) return;
    navigatorKey.currentState?.pushNamedAndRemoveUntil(
        NexoRoutes.login, (_) => false);
  }
}

class _LoadingPage extends StatelessWidget {
  const _LoadingPage();

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: SafeArea(
          child: Center(child: CircularProgressIndicator()),
        ),
      );
}
