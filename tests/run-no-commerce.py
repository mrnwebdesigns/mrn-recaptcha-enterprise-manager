#!/usr/bin/env python3
"""Qualify an exact ZIP in disposable WordPress; no WooCommerce or WPForms."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
import zipfile

TESTS = Path(__file__).resolve().parent
BASE = 'http://127.0.0.1:8765'
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wordpress', type=Path, required=True)
parser.add_argument('--sqlite', type=Path, required=True)
parser.add_argument('--package', type=Path, required=True)
parser.add_argument('--package-sha256', required=True)
parser.add_argument('--reports', type=Path, required=True)
parser.add_argument('--mrn-qa', action='store_true', help='Run release MRN QA while the disposable runtime is available')
args = parser.parse_args()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def extract(path, expected, destination):
    if digest(path) != expected:
        raise ValueError('Archive checksum mismatch: ' + path.name)
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ValueError('Duplicate ZIP entries')
        for info in archive.infolist():
            name = PurePosixPath(info.filename)
            if name.is_absolute() or '..' in name.parts or '\\' in info.filename or ((info.external_attr >> 16) & 0o170000) == 0o120000:
                raise ValueError('Unsafe ZIP entry')
        archive.extractall(destination)


def literal(value):
    return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"


args.reports.mkdir(parents=True, exist_ok=False)
with socket.socket() as guard:
    guard.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    guard.bind(('127.0.0.1', 8765))
with tempfile.TemporaryDirectory(prefix='mrn-recaptcha-no-commerce-') as temporary:
    root = Path(temporary)
    extract(args.wordpress, '8fc96c59a78b7219e4a130222b7fadb51b03e503e8b0123beaa7e28961c21ce2', root)
    extract(args.sqlite, '1602e75577ad9b3a7e3e4a6a44a81b9541cdee2124d48928faf61c6fd3cd4f74', root)
    public = root / 'wordpress'
    content = public / 'wp-content'
    extract(args.package, args.package_sha256, content / 'plugins')
    plugin = content / 'plugins/mrn-recaptcha-enterprise-manager'
    # The runtime bytes must be this task's unchanged accepted production code.
    for path in [plugin / 'mrn-recaptcha-enterprise-manager.php', *plugin.joinpath('includes').rglob('*.php')]:
        source = TESTS.parent / path.relative_to(plugin)
        if digest(path) != digest(source):
            raise ValueError('Package runtime differs from the source under test')
    manifest = json.loads((plugin / 'assets/manifest.json').read_text())
    for variants in manifest['assets'].values():
        for variant in ('source', 'minified'):
            entry = variants[variant]
            if digest(plugin / entry['path']) != entry['sha256']:
                raise ValueError('Packaged asset differs from its manifest')
    sqlite = root / 'sqlite-database-integration'
    (content / 'db.php').write_text((sqlite / 'db.copy').read_text().replace('{SQLITE_IMPLEMENTATION_FOLDER_PATH}', str(sqlite)))
    config = """<?php
define('DB_NAME','isolated_no_commerce');define('DB_USER','');define('DB_PASSWORD','');define('DB_HOST','');
define('DB_ENGINE','sqlite');define('DB_DIR',__DIR__.'/wp-content/database/');define('WP_HTTP_BLOCK_EXTERNAL',true);
define('WP_ENVIRONMENT_TYPE','local');define('MRN_RECAPTCHA_ISOLATED_TEST',true);
define('WP_HOME','http://127.0.0.1:8765');define('WP_SITEURL','http://127.0.0.1:8765');
define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('WP_DEBUG_LOG',REPORT_LOG);
define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_AUTO_UPDATE_CORE',false);
$table_prefix='fixture_';if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require ABSPATH.'wp-settings.php';
""".replace('REPORT_LOG', literal(args.reports / 'runtime.log'))
    (public / 'wp-config.php').write_text(config)
    (public / '.mrn-recaptcha-fixture').touch()
    environment = dict(os.environ, MRN_RECAPTCHA_TEST_ROOT=str(public), MRN_RECAPTCHA_REPORT_DIR=str(args.reports))

    def php(body, installing=False):
        program = '<?php\n' + ("define('WP_INSTALLING',true);\n" if installing else '') + 'require ' + literal(public / 'wp-load.php') + ';\n' + body
        result = subprocess.run(['php', '-d', 'memory_limit=1G'], input=program, text=True, capture_output=True, env=environment, timeout=90)
        if result.returncode or result.stderr.strip():
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout

    php("require ABSPATH.'wp-admin/includes/upgrade.php';wp_install('Isolated no-commerce qualification','fixture-admin','fixture@example.invalid',false,''," + literal(secrets.token_urlsafe(30)) + ");", True)
    mu = content / 'mu-plugins'
    mu.mkdir()
    shutil.copy(TESTS / 'fixtures/local-only.php', mu / 'fixture.php')
    (mu / 'post-types.php').write_text("<?php add_action('init',static function(){register_post_type('mrn_fixture',['public'=>true,'supports'=>['title','editor','comments'],'show_in_rest'=>true]);register_post_type('product',['public'=>true,'supports'=>['title','comments']]);});")
    php("require_once ABSPATH.'wp-admin/includes/plugin.php';$error=activate_plugin('mrn-recaptcha-enterprise-manager/mrn-recaptcha-enterprise-manager.php');if(is_wp_error($error))throw new RuntimeException($error->get_error_code());")
    with (args.reports / 'integration.log').open('w') as log:
        result = subprocess.run(['php', str(TESTS / 'integration-no-commerce.php')], stdout=log, stderr=subprocess.STDOUT, env=environment, timeout=120)
        if result.returncode:
            raise RuntimeError('Integration failed; inspect integration.log')
    with (args.reports / 'server.log').open('w') as log:
        server = subprocess.Popen(['php', '-d', 'memory_limit=1G', '-S', '127.0.0.1:8765', '-t', str(public), str(TESTS / 'fixtures/router.php')], stdout=log, stderr=log, env=environment)
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError('Fixture server stopped')
                try:
                    with urllib.request.urlopen(BASE, timeout=1) as response:
                        if response.status == 200:
                            break
                except OSError:
                    time.sleep(0.05)
            else:
                raise RuntimeError('Fixture server did not become ready')
            with (args.reports / 'browser.log').open('w') as browser_log:
                result = subprocess.run(['node', str(TESTS / 'browser-no-commerce.cjs')], stdout=browser_log, stderr=subprocess.STDOUT, env=environment, timeout=180)
                if result.returncode:
                    raise RuntimeError('Browser failed; inspect browser.log')
            if args.mrn_qa:
                with (args.reports / 'mrn-qa.log').open('w') as qa_log:
                    result = subprocess.run(['mrn-qa', 'run', '--project-root', str(TESTS.parent), '--scope', 'site-only',
                                             '--mode', 'release', '--smoke-strict', '1', '--site-url', BASE, '--site-path', str(public),
                                             '--run-smoke', 'always', '--run-accessibility', 'always', '--run-performance', 'always',
                                             '--run-api', 'always', '--run-cwv', 'always', '--run-phpcbf', 'never',
                                             '--output-file', str(args.reports / 'mrn-qa.md')],
                                            stdout=qa_log, stderr=subprocess.STDOUT,
                                            env=dict(environment, MRN_QA_CODE_ANALYSIS_SCOPE='all'), timeout=600)
                    if result.returncode:
                        raise RuntimeError('MRN QA failed; inspect mrn-qa.md and mrn-qa.log')
        finally:
            server.terminate()
            server.wait(timeout=10)
    debug = args.reports / 'runtime.log'
    if debug.exists() and debug.read_text().strip():
        raise RuntimeError('Runtime warnings/errors block qualification; inspect runtime.log')
    receipt = {'status': 'pass', 'wordpress': '7.1.2', 'sqlite': '3.0.2', 'package': args.package.name, 'package_sha256': args.package_sha256,
               'source_commit': manifest['source_commit'], 'woocommerce_installed': False, 'wpforms_installed': False,
               'runtime': BASE, 'external_provider': 'mocked; genuine Google qualification remains pending',
               'outbound_mail': 'intercepted', 'fixture_destroyed_on_exit': True}
    (args.reports / 'qualification.json').write_text(json.dumps(receipt, indent=2) + '\n')
    print(json.dumps(receipt, indent=2))
