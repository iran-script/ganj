import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/app/nexo_components.dart';
import 'package:nexo_player/app/nexo_routes.dart';
import 'package:nexo_player/app/nexo_theme.dart';

void main() {
  testWidgets('team management replaces QR in the same navigation slot',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      theme: NexoTheme.data,
      home: const Scaffold(
        bottomNavigationBar: NexoBottomNavigation(selectedIndex: 0),
      ),
      onGenerateRoute: (settings) => MaterialPageRoute<void>(
        settings: settings,
        builder: (_) => Scaffold(body: Text(settings.name ?? '')),
      ),
    ));

    expect(find.text('مدیریت تیم'), findsOneWidget);
    expect(find.byIcon(Icons.groups_2_outlined), findsOneWidget);
    expect(find.text('اسکن QR'), findsNothing);
    expect(NexoRoutes.scan, '/scan');

    await tester.tap(find.text('مدیریت تیم'));
    await tester.pumpAndSettle();
    expect(find.text(NexoRoutes.teams), findsOneWidget);
  });
}
