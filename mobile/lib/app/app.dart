import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'nexo_theme.dart';
import '../core/api/api_client.dart';
import '../core/auth/auth_controller.dart';
import '../features/auth/login_page.dart';
import '../features/home/home_page.dart';

class NexoApp extends StatefulWidget {
  const NexoApp({this.authController, super.key});

  final AuthController? authController;

  @override
  State<NexoApp> createState() => _NexoAppState();
}

class _NexoAppState extends State<NexoApp> {
  late final AuthController auth;

  @override
  void initState() {
    super.initState();
    auth = widget.authController ?? AuthController(ApiClient());
    auth.restore();
  }

  @override
  void dispose() {
    auth.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: auth,
      builder: (context, _) => MaterialApp(
        title: 'NEXO',
        debugShowCheckedModeBanner: false,
        theme: NexoTheme.data,
        locale: const Locale('fa'),
        supportedLocales: const [Locale('fa'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate
        ],
        home: auth.loading
            ? const _LoadingPage()
            : auth.authenticated
                ? HomePage(auth: auth)
                : LoginPage(auth: auth),
      ),
    );
  }
}

class _LoadingPage extends StatelessWidget {
  const _LoadingPage();

  @override
  Widget build(BuildContext context) =>
      const Scaffold(body: Center(child: CircularProgressIndicator()));
}
