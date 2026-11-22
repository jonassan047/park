<?php

namespace App\Support;

// 技術マスタの辞書の中身（SkillCatalog::CATALOG はこれを使う）。
//
// 書き方のルール（2026-11-21 TEZZO の指示。Provider⇔SES⇔エンジニアの間で実際に書かれる表記だけを別名にする）：
//  1. 製品名・言語名・フレームワーク名は、英字の表記を正式な名前にする。カナ書きは、現場で実際に書かれるものだけ別名に入れる。
//     例：Java を「ジャバ」と書く人はいないので入れない。Agile を「アジャイル」、Scrum Master を「スクラムマスター」と書くのは普通なので入れる。
//  2. 長音の有無（マネージャー／マネージャ、サーバー／サーバ、ミドルウェア／ミドルウエア）は両方を別名に入れる。
//  3. 別名の区切りは「、」。別名の中に「/」「・」「,」は書かない（parse_list が分けてしまう。「CI/CD」のような名前そのものは可）。
//  4. 別名はほかの行の名前・別名とぶつけない（ぶつかると sync が知らせる）。1つの言葉は1か所だけ。
//  5. 文章から拾う分類（lang・fw・db・os・cloud・infra・tool）の別名は、ほかの言葉の一部に当たりやすい短いカナを避ける。
class SkillCatalogData
{
    // 分類 => [正式な名前 => 別名（「、」区切り）]
    const CATALOG = [
        'lang' => [
            'Java' => 'Java8、Java11、Java17、Java21、JavaSE、JavaEE、J2EE、Jakarta EE', 'PHP' => 'PHP7、PHP8',
            'JavaScript' => 'JS、ECMAScript、ES6、ES2015、Vanilla JS', 'TypeScript' => 'TS',
            'Python' => 'Python3、Python2、パイソン', 'Ruby' => 'ルビー', 'Go' => 'Golang、Go言語',
            'C' => 'C言語', 'C++' => 'CPP、C＋＋', 'C#' => 'CSharp、C＃、シーシャープ', 'VB.NET' => 'VB.net、VBNET',
            'Visual Basic' => 'VB、VB6、VB6.0、VisualBasic、ビジュアルベーシック', 'VBA' => 'Excel VBA、Excelマクロ、Accessマクロ、Officeマクロ',
            'VBScript' => 'VBS', 'Kotlin' => '', 'Swift' => '', 'Objective-C' => 'ObjC、Objective C', 'Scala' => '', 'Rust' => '', 'Perl' => '',
            'COBOL' => 'コボル', 'PL/SQL' => 'PLSQL', 'T-SQL' => 'TSQL、Transact-SQL', 'SQL' => '', 'HTML' => 'HTML5、XHTML', 'CSS' => 'CSS3',
            'Sass' => 'SCSS', 'Less' => '', 'Shell' => 'Bash、シェルスクリプト、シェル、sh、ksh、zsh', 'PowerShell' => 'パワーシェル', 'R' => 'R言語', 'Dart' => '',
            'ABAP' => 'ABAP OO', 'XML' => '', 'JSON' => '', 'YAML' => 'YML', 'Markdown' => '', 'RPG' => 'RPGIV、RPG400',
            'Google Apps Script' => 'GAS',
            'Lua' => '', 'Haskell' => '', 'Erlang' => '', 'Elixir' => '', 'Clojure' => '', 'Groovy' => '', 'F#' => 'FSharp', 'OCaml' => '', 'Julia' => '',
            'Fortran' => 'フォートラン', 'Assembler' => 'アセンブラ、アセンブリ、アセンブリ言語、ASM', 'Delphi' => 'Object Pascal', 'ActionScript' => 'AS3',
            'Smalltalk' => '', 'Lisp' => '', 'Prolog' => '', 'MATLAB' => 'マトラブ', 'Apex' => '', 'Solidity' => '', 'WebAssembly' => 'Wasm',
            'Verilog' => '', 'VHDL' => '', 'JCL' => '', 'PL/I' => 'PLI', 'Natural' => 'NATURAL', 'CICS' => '', 'Ladder' => 'ラダー言語、ラダー図',
            'AWK' => '', 'Zig' => '', 'Nim' => '', 'Crystal' => '',
        ],
        'fw' => [
            // PHP
            'Laravel' => 'ララベル', 'CakePHP' => 'Cake PHP、CakePHP3、CakePHP4', 'CodeIgniter' => 'Codeigniter', 'Symfony' => '', 'Zend Framework' => 'ZendFramework、ZendFramework2、Zend', 'FuelPHP' => '',
            'Ethna' => '', 'Slim' => '', 'Yii' => 'Yii2', 'Phalcon' => '', 'Laminas' => '', 'Lumen' => '', 'Livewire' => '', 'Inertia.js' => 'Inertia', 'Smarty' => '', 'Twig' => '',
            'Doctrine ORM' => 'Doctrine', 'Eloquent' => 'Eloquent ORM',
            // Java
            'Spring' => 'Spring Framework、スプリング', 'Spring Boot' => 'SpringBoot、スプリングブート', 'Spring MVC' => 'SpringMVC', 'Spring Security' => 'SpringSecurity',
            'Spring Batch' => 'SpringBatch', 'Spring Data' => 'SpringData', 'Struts' => 'Struts2', 'Seasar2' => 'S2Dao、S2Struts', 'MyBatis' => 'iBATIS',
            'Hibernate' => '', 'JPA' => 'Java Persistence API', 'JSF' => 'JavaServer Faces', 'Servlet' => 'Java Servlet、サーブレット', 'JSP' => 'JavaServer Pages',
            'Play Framework' => 'Play2', 'Quarkus' => '', 'Micronaut' => '', 'Vert.x' => 'Vertx', 'Lombok' => '', 'Thymeleaf' => '', 'JavaFX' => '', 'Apache Camel' => 'Camel',
            // Ruby
            'Ruby on Rails' => 'Rails、RoR、Ruby on Rails 6、Ruby on Rails 7', 'Sinatra' => '',
            // Python・データ・AI
            'Django' => 'Django REST Framework、DRF', 'Flask' => '', 'FastAPI' => '', 'Tornado' => '', 'Celery' => '', 'SQLAlchemy' => '', 'Pydantic' => '',
            'Pandas' => '', 'NumPy' => '', 'SciPy' => '', 'scikit-learn' => 'sklearn、scikit learn', 'Matplotlib' => '', 'TensorFlow' => 'テンソルフロー', 'PyTorch' => '', 'Keras' => '',
            'OpenCV' => '', 'LangChain' => '', 'Hugging Face' => 'Transformers、HuggingFace', 'Apache Spark' => 'Spark、PySpark', 'Hadoop' => 'Apache Hadoop、HDFS',
            // JavaScript
            'Express' => 'Express.js', 'NestJS' => 'Nest.js', 'Koa' => '', 'Next.js' => 'Next', 'Nuxt.js' => 'Nuxt', 'React' => 'React.js、ReactJS、リアクト', 'Vue.js' => 'Vue、Vue3、Vue2、VueJS、Vue 2、Vue 3',
            'Angular' => 'AngularJS、アンギュラー', 'jQuery' => 'ジェイクエリ', 'Svelte' => 'SvelteKit', 'Remix' => '', 'Gatsby' => '', 'Astro' => '', 'Redux' => 'Redux Toolkit', 'Vuex' => '', 'Pinia' => '',
            'MobX' => '', 'RxJS' => '', 'Ember.js' => 'Ember', 'Backbone.js' => 'Backbone', 'Three.js' => 'Threejs', 'D3.js' => 'D3', 'Chart.js' => 'Chartjs', 'Socket.IO' => 'Socketio',
            'Prisma' => '', 'TypeORM' => '', 'Sequelize' => '', 'Material UI' => 'MUI、Material-UI', 'Ant Design' => 'antd', 'Chakra UI' => '', 'Vuetify' => '', 'Storybook' => '',
            'Bootstrap' => 'ブートストラップ', 'Tailwind CSS' => 'Tailwind', 'Node.js' => 'Node、NodeJS', 'Electron' => '', 'Deno' => '', 'Bun' => '',
            // モバイル・ゲーム
            'React Native' => '', 'Flutter' => 'フラッター', 'Expo' => '', 'Ionic' => '', 'Cordova' => 'PhoneGap', 'Xamarin' => '', '.NET MAUI' => 'MAUI', 'SwiftUI' => '', 'UIKit' => '',
            'Jetpack Compose' => 'Compose', 'Unity' => 'ユニティ', 'Unreal Engine' => 'UE4、UE5、Unreal、アンリアルエンジン', 'Cocos2d' => 'Cocos2d-x、Cocos Creator',
            // .NET
            '.NET' => 'ASP.NET、.NET Framework、.NET Core、ASP.NET MVC、ASP.NET Core、.NET5、.NET6、.NET7、.NET8、.NET9', 'WPF' => 'Windows Presentation Foundation', 'WinForms' => 'Windows Forms',
            'Blazor' => '', 'Entity Framework' => 'EF Core、EntityFramework',
            // Go・Rust ほか
            'Gin' => '', 'Echo' => '', 'Actix' => 'Actix Web', 'Axum' => '', 'Rocket' => '', 'Ktor' => '', 'Vapor' => '', 'Phoenix' => '',
            // CMS・パッケージ
            'Drupal' => '', 'WordPress' => 'WP、ワードプレス', 'EC-CUBE' => 'ECCUBE', 'Magento' => '', 'Shopify' => '', 'Movable Type' => '', 'Joomla' => '', 'concrete5' => '',
            'SAP' => 'SAP ERP、SAP S4HANA、S4HANA、SAP ECC、SAP R3', 'Oracle EBS' => 'Oracle E-Business Suite', 'Dynamics 365' => 'Dynamics', 'kintone' => 'キントーン',
            'Power Apps' => 'PowerApps', 'Power Automate' => 'PowerAutomate', 'Power Platform' => '', 'OutSystems' => '', 'Mendix' => '', 'Pega' => '',
        ],
        'db' => [
            'MySQL' => 'MySql、MySQL5、MySQL8', 'MariaDB' => '', 'PostgreSQL' => 'Postgres、psql、ポスグレ、ポストグレス、ポストグレSQL', 'Oracle' => 'Oracle DB、Oracle Database、Oracle11g、Oracle12c、Oracle19c、オラクル',
            'SQL Server' => 'SQLServer、MSSQL、Microsoft SQL Server', 'SQLite' => '', 'DB2' => 'Db2', 'MongoDB' => 'Mongo', 'Redis' => '', 'DynamoDB' => 'Amazon DynamoDB',
            'Elasticsearch' => 'Elastic Search', 'OpenSearch' => '', 'Solr' => '', 'Access' => 'MS Access、Microsoft Access', 'FileMaker' => 'ファイルメーカー', 'BigQuery' => '', 'Snowflake' => '',
            'Redshift' => 'Amazon Redshift', 'dbt' => '', 'Amazon Aurora' => 'Aurora', 'Cassandra' => 'Apache Cassandra', 'Neo4j' => '', 'CouchDB' => '', 'Cosmos DB' => 'CosmosDB、Azure Cosmos DB',
            'Firestore' => 'Cloud Firestore', 'Cloud Spanner' => 'Spanner', 'TiDB' => '', 'CockroachDB' => '', 'Memcached' => '', 'InfluxDB' => '', 'HBase' => '', 'Apache Hive' => 'Hive',
            'Presto' => '', 'Trino' => '', 'Teradata' => '', 'Greenplum' => '', 'Vertica' => '', 'Netezza' => '', 'Sybase' => '', 'Informix' => '', 'HiRDB' => '', 'Symfoware' => '',
            'PowerGres' => '', 'DuckDB' => '', 'Vector DB' => 'ベクトルDB、ベクターDB、Vector Database、Pinecone', 'ISAM' => '', 'IMS' => '', 'RDB' => 'リレーショナルデータベース',
            'NoSQL' => '',
        ],
        'os' => [
            'Linux' => 'リナックス', 'Windows' => 'ウィンドウズ、Windows10、Windows11、Windows7', 'Windows Server' => 'WindowsServer、Windows Server 2016、Windows Server 2019、Windows Server 2022',
            'macOS' => 'Mac、Mac OS、OS X、MacOS', 'UNIX' => 'Unix、ユニックス', 'Solaris' => 'Oracle Solaris', 'AIX' => '', 'HP-UX' => 'HPUX', 'CentOS' => '', 'Rocky Linux' => 'RockyLinux',
            'AlmaLinux' => 'Alma Linux', 'Red Hat Enterprise Linux' => 'RHEL、Red Hat、レッドハット', 'Ubuntu' => 'ウブンツ', 'Debian' => '', 'Amazon Linux' => 'Amazon Linux 2、AmazonLinux、AL2',
            'SUSE' => 'SLES、SUSE Linux', 'Fedora' => '', 'FreeBSD' => '', 'Android' => 'アンドロイド', 'iOS' => '', 'メインフレーム' => '汎用機、大型汎用機、ホスト系',
            'z/OS' => 'zOS', 'OS/400' => 'OS400、IBM i、AS400、iSeries', 'RTOS' => 'リアルタイムOS', 'FreeRTOS' => '', 'ITRON' => 'μITRON、TRON', 'Embedded Linux' => '組込みLinux、組み込みLinux、組込Linux',
            'WSL' => 'WSL2',
        ],
        'cloud' => [
            'AWS' => 'Amazon Web Services、アマゾンウェブサービス', 'Azure' => 'Microsoft Azure、アジュール', 'GCP' => 'Google Cloud、Google Cloud Platform、グーグルクラウド', 'Firebase' => '', 'Heroku' => '',
            'Salesforce' => 'セールスフォース、Sales Cloud、Service Cloud', 'Amazon EC2' => 'EC2', 'Amazon S3' => 'S3', 'AWS Lambda' => 'Lambda', 'Amazon Athena' => 'Athena', 'Amazon RDS' => 'RDS',
            'Amazon ECS' => 'ECS', 'Amazon EKS' => 'EKS', 'AWS Fargate' => 'Fargate', 'Amazon CloudFront' => 'CloudFront', 'Amazon Route 53' => 'Route53', 'Amazon VPC' => 'VPC',
            'AWS IAM' => 'IAM', 'Amazon CloudWatch' => 'CloudWatch', 'AWS CloudFormation' => 'CloudFormation', 'AWS CDK' => 'CDK', 'Amazon SQS' => 'SQS', 'Amazon SNS' => '',
            'Amazon Kinesis' => 'Kinesis', 'AWS Glue' => 'Glue', 'Amazon API Gateway' => 'API Gateway', 'AWS Step Functions' => 'Step Functions', 'Amazon Cognito' => 'Cognito',
            'Amazon SageMaker' => 'SageMaker', 'Amazon Bedrock' => 'Bedrock', 'AWS Amplify' => 'Amplify', 'Amazon Connect' => '', 'AWS Elastic Beanstalk' => 'Beanstalk', 'AWS CodePipeline' => '',
            'AWS CodeBuild' => '', 'AWS CodeDeploy' => '', 'AWS Systems Manager' => 'Systems Manager、SSM', 'AWS WAF' => '', 'AWS KMS' => '', 'AWS Secrets Manager' => 'Secrets Manager',
            'Amazon EMR' => 'EMR', 'Amazon QuickSight' => 'QuickSight', 'Amazon EventBridge' => 'EventBridge', 'Amazon SES' => '', 'AWS Direct Connect' => 'Direct Connect',
            'Azure Functions' => '', 'Azure DevOps' => 'AzureDevOps、VSTS、TFS', 'Microsoft Entra ID' => 'Azure Active Directory、Azure AD、Entra ID', 'Azure App Service' => '',
            'Azure Kubernetes Service' => 'AKS', 'Azure Blob Storage' => 'Blob Storage', 'Azure SQL Database' => 'Azure SQL', 'Azure OpenAI' => 'Azure OpenAI Service',
            'Azure Synapse Analytics' => 'Synapse', 'Azure Data Factory' => 'ADF', 'Microsoft Fabric' => '',
            'Google Compute Engine' => 'GCE', 'Google Kubernetes Engine' => 'GKE', 'Cloud Run' => '', 'Cloud Functions' => '', 'Cloud PubSub' => 'PubSub', 'Vertex AI' => '',
            'Google Workspace' => 'G Suite、GSuite、Gsuite', 'Microsoft 365' => 'M365、Office 365、Office365、O365', 'ServiceNow' => '', 'Dify' => '', 'LINE Messaging API' => 'LIFF、LINE API',
            'Vercel' => '', 'Netlify' => '', 'Cloudflare' => '', 'Oracle Cloud' => 'OCI', 'IBM Cloud' => '', 'Alibaba Cloud' => 'Aliyun', 'さくらのクラウド' => 'Sakura Cloud、さくらクラウド',
            'Zendesk' => '', 'HubSpot' => '', 'Twilio' => '', 'Stripe' => '', 'Garoon' => 'ガルーン',
        ],
        'infra' => [
            'Docker' => 'ドッカー、Docker Compose、docker-compose', 'Kubernetes' => 'k8s', 'Helm' => '', 'Istio' => '', 'Terraform' => '', 'Ansible' => '', 'Chef' => '', 'Puppet' => '', 'Vagrant' => '', 'Packer' => '',
            'Apache' => 'Apache HTTP Server、httpd', 'Nginx' => 'エンジンエックス', 'Tomcat' => 'Apache Tomcat', 'IIS' => '', 'JBoss' => 'WildFly', 'WebLogic' => 'WebLogic Server', 'WebSphere' => 'WebSphere Application Server',
            'Jetty' => '', 'HAProxy' => '', 'Varnish' => '', 'Envoy' => '', 'Kong' => '',
            'Jenkins' => 'ジェンキンス', 'CircleCI' => '', 'GitHub Actions' => 'GitHubActions', 'GitLab CI' => 'GitLabCI', 'Travis CI' => 'TravisCI', 'Argo CD' => 'ArgoCD',
            'Zabbix' => 'ザビックス', 'Nagios' => '', 'Prometheus' => '', 'Grafana' => '', 'Datadog' => '', 'New Relic' => 'NewRelic', 'Splunk' => '', 'ELK Stack' => 'ELK', 'Logstash' => '', 'Kibana' => '', 'Fluentd' => '',
            'Hinemos' => '', 'JP1' => '', 'Systemwalker' => 'システムウォーカー',
            'Keycloak' => '', 'Okta' => '', 'Active Directory' => 'AD、ActiveDirectory、Windows AD', 'LDAP' => 'OpenLDAP', 'SSO' => 'シングルサインオン', 'SSL' => 'TLS、SSL証明書', 'Samba' => '',
            'VMware' => 'vSphere、ESXi、vCenter', 'Hyper-V' => 'HyperV', 'KVM' => '', 'Citrix' => '', 'OpenStack' => '',
            'Cisco' => '', 'Juniper' => '', 'F5 BIG-IP' => 'BIG-IP、BIGIP', 'Fortinet' => 'FortiGate', 'Palo Alto' => 'パロアルト',
            'ネットワーク' => 'TCP IP、TCPIP、NW', 'VPN' => '', 'DNS' => '', 'DHCP' => '', 'BGP' => '', 'OSPF' => '', 'VLAN' => '', 'ロードバランサー' => 'ロードバランサ、LB',
            'ファイアウォール' => 'Firewall、FW機器', 'プロキシ' => 'プロキシー、Proxy', 'WAF' => 'Web Application Firewall', 'SIEM' => '',
            'Kafka' => 'Apache Kafka', 'RabbitMQ' => '', 'ActiveMQ' => '', 'IBM MQ' => 'MQ Series、WebSphere MQ、MQSeries', 'Airflow' => 'Apache Airflow', 'Flink' => 'Apache Flink',
            'Postfix' => '', 'Exchange Server' => 'Exchange', 'Veeam' => '', 'NetBackup' => '', 'Arcserve' => ''
        ],
        'tool' => [
            'Git' => '', 'GitHub' => 'ギットハブ', 'GitLab' => '', 'Bitbucket' => '', 'Sourcetree' => 'Source Tree', 'TortoiseSVN' => '', 'TortoiseGit' => '', 'Subversion' => 'SVN',
            'Backlog' => '', 'Redmine' => '', 'Jira' => 'JIRA', 'Confluence' => '', 'Slack' => '', 'Microsoft Teams' => 'Teams', 'Chatwork' => 'チャットワーク', 'Notion' => '', 'Trello' => '', 'Asana' => '',
            'Excel' => 'Microsoft Excel、MS Excel、エクセル', 'PowerPoint' => 'パワーポイント、パワポ、MS PowerPoint', 'Word' => 'Microsoft Word、MS Word', 'Visio' => '', 'Outlook' => '',
            'Photoshop' => 'フォトショップ、フォトショ', 'Illustrator' => 'イラストレーター', 'Adobe XD' => 'XD', 'Premiere Pro' => 'Premiere', 'After Effects' => '', 'InDesign' => '',
            'Dreamweaver' => 'ドリームウィーバー', 'Flash' => 'Adobe Flash', 'Adobe Director' => 'Director、Macromedia Director', 'ToolBook' => '', 'AgileWorks' => '', 'Figma' => 'フィグマ', 'Sketch' => '',
            'Eclipse' => 'エクリプス', 'Visual Studio' => '', 'Visual Studio Code' => 'VS Code、VSCode', 'IntelliJ IDEA' => 'IntelliJ', 'PhpStorm' => '', 'PyCharm' => '', 'WebStorm' => '',
            'Android Studio' => '', 'Xcode' => '', 'NetBeans' => '', 'Vim' => '', 'Emacs' => '',
            'Postman' => '', 'Swagger' => 'OpenAPI、Swagger UI', 'SoapUI' => '', 'JMeter' => 'Apache JMeter', 'Selenium' => 'セレニウム', 'Cypress' => '', 'Playwright' => '', 'Appium' => '', 'Katalon' => '',
            'UFT' => 'QTP、UFT One', 'JUnit' => 'JUnit4、JUnit5', 'TestNG' => '', 'Mockito' => '', 'Cucumber' => '', 'PHPUnit' => '', 'pytest' => '', 'Jest' => '', 'Mocha' => '', 'SonarQube' => '',
            'Maven' => 'Apache Maven', 'Gradle' => '', 'Apache Ant' => '', 'npm' => '', 'yarn' => '', 'pnpm' => '', 'Composer' => '', 'pip' => '', 'Bundler' => '', 'CMake' => '', 'Webpack' => '', 'Vite' => '',
            'Babel' => '', 'ESLint' => '', 'Prettier' => '',
            'Tableau' => 'タブロー', 'Power BI' => 'PowerBI', 'Looker Studio' => 'Google Data Studio、データポータル', 'Looker' => '', 'QlikView' => '', 'Qlik Sense' => '', 'Domo' => '', 'Metabase' => '',
            'Redash' => '', 'Jupyter' => 'Jupyter Notebook、JupyterLab', 'Google Colab' => 'Colab', 'Databricks' => '', 'Talend' => '', 'Informatica' => '', 'DataSpider' => 'DataSpider Servista',
            'ASTERIA Warp' => 'ASTERIA', 'Pentaho' => '', 'Alteryx' => '', 'UiPath' => 'ユーアイパス', 'WinActor' => 'ウィンアクター', 'Automation Anywhere' => '', 'BizRobo' => '', 'Zapier' => '',
            'Genesys Cloud' => '', 'JUST.DB' => '', 'Dataiku' => '', 'Streamlit' => '', 'ERP' => '', 'Tera Term' => 'TeraTerm、テラターム', 'WinSCP' => '', 'FileZilla' => '', 'PuTTY' => '',
            'WinMerge' => '', 'Wireshark' => '', 'Fiddler' => '', 'Burp Suite' => '', 'OWASP ZAP' => 'ZAP', 'Enterprise Architect' => '', 'astah*' => 'Astah', 'StarUML' => '', 'PlantUML' => '', 'Mermaid' => '',
            'ChatGPT' => 'チャットGPT', 'OpenAI API' => 'OpenAI', 'Claude' => 'Claude Code', 'Gemini' => '', 'GitHub Copilot' => 'Copilot', 'Cursor' => '', 'LLM' => '大規模言語モデル',
        ],
        'method' => [
            // 工程
            '要件定義' => '要求定義、要件分析、要件整理', '基本設計' => '外部設計、基本設計書', '詳細設計' => '内部設計、詳細設計書、プログラム設計', '画面設計' => '画面設計書、UI設計',
            'DB設計' => 'データベース設計、テーブル設計、ER設計', 'インフラ設計' => 'インフラ基本設計、ネットワーク設計、サーバー設計、サーバ設計', 'アーキテクチャ設計' => 'システム設計、アーキテクチャ策定',
            'API設計' => 'インターフェース設計、IF設計', '実装' => '製造、プログラミング、コーディング、PG作業', '単体テスト' => 'UT、単体試験、ユニットテスト', '結合テスト' => 'IT、結合試験、統合テスト',
            '総合テスト' => 'システムテスト、ST、総合試験', '受入テスト' => 'UAT、受け入れテスト、ユーザー受入テスト、ユーザ受入テスト、受入試験', '性能テスト' => '負荷テスト、パフォーマンステスト、性能試験',
            'テスト設計' => 'テスト仕様書、テスト仕様書作成、テストケース作成、テスト計画', 'テスト自動化' => '自動テスト、テスト自動化ツール', 'テスト実施' => 'テスト実行、試験実施',
            '運用・保守' => '運用、保守、運用保守', '運用設計' => '運用手順設計', '移行' => 'データ移行、システム移行、移行作業、マイグレーション', 'リリース' => 'リリース作業、本番リリース', '障害対応' => 'トラブルシューティング、障害調査、障害切り分け',
            '見積もり' => '見積り、見積、工数見積もり', '上流工程' => '', '下流工程' => '',
            // 開発手法
            'アジャイル' => 'Agile、アジャイル開発、Agile開発、スクラム、Scrum、スクラム開発', 'カンバン' => 'Kanban', 'XP' => 'エクストリームプログラミング、Extreme Programming', 'ウォーターフォール' => 'Waterfall、ウォーターフォール型、ウォーターフォール開発',
            'DevOps' => 'デブオプス', 'CI/CD' => 'CICD、継続的インテグレーション、継続的デリバリー', 'TDD' => 'テスト駆動開発', 'BDD' => 'ビヘイビア駆動開発', 'DDD' => 'ドメイン駆動設計',
            'マイクロサービス' => 'Microservices、マイクロサービスアーキテクチャ', 'サーバーレス' => 'Serverless、サーバレス', 'IaC' => 'Infrastructure as Code、インフラのコード化', 'リファクタリング' => 'Refactoring、リファクタ',
            'コードレビュー' => 'Code Review、ソースレビュー', '設計書レビュー' => 'レビュー、設計レビュー', 'ペアプログラミング' => 'ペアプロ', 'モブプログラミング' => 'モブプロ', 'オブジェクト指向' => 'OOP、OOD、OOA',
            'デザインパターン' => 'Design Pattern', 'UML' => 'ユーエムエル', 'ER図' => 'ERD', 'SPA' => 'Single Page Application', 'PWA' => 'Progressive Web App',
            'REST API' => 'REST、API開発、WebAPI、RESTful、RESTful API', 'GraphQL' => '', 'gRPC' => '', 'WebSocket' => '', 'SOAP' => '', 'OAuth' => 'OAuth2、OAuth 2.0', 'OpenID Connect' => 'OIDC', 'JWT' => 'JSON Web Token',
            'SAML' => '', 'API連携' => '外部連携、システム連携、外部システム連携、インターフェース開発', 'バッチ処理' => 'バッチ、バッチ開発、バッチ設計', 'ストアドプロシージャ' => 'ストアド、ストアドプロシージャ開発',
            'SQLチューニング' => 'チューニング、パフォーマンスチューニング、DBチューニング', 'レスポンシブデザイン' => 'レスポンシブ、レスポンシブ対応', 'UXデザイン' => 'UX、ユーザー体験設計', 'SEO' => 'SEO対策',
            'アクセシビリティ' => 'Accessibility、WCAG', 'オフショア開発' => 'オフショア', 'ニアショア開発' => 'ニアショア', 'ラボ型開発' => 'ラボ契約', 'PoC' => '概念実証、実証実験、PoC開発', 'MVP' => '', 'プロトタイピング' => 'プロトタイプ作成、試作',
            // 移行・刷新
            'レガシー刷新' => 'リプレース、リプレイス、システムリプレース、リニューアル、システムリニューアル、モダナイゼーション', 'クラウド移行' => 'クラウドマイグレーション、クラウド化、クラウドシフト', 'オンプレミス' => 'オンプレ、オンプレ環境',
            'リホスト' => '', 'バージョンアップ' => 'バージョンアップ対応、アップデート対応', 'システム運用' => '運用監視、監視、システム監視、運用オペレーション', 'ヘルプデスク' => 'ITサポート、社内ヘルプデスク、PCキッティング',
            'サービスデスク' => '', 'ITIL' => 'ITIL v3、ITIL v4', 'サーバー構築' => 'サーバ構築、サーバ構築作業、インフラ構築', 'ミドルウェア構築' => 'ミドルウェア設定、ミドルウエア構築、ミドルウェア運用、ミドルウェア', 'セキュリティ対策' => 'セキュリティ、情報セキュリティ、セキュア開発、セキュリティ設計', 'ネットワーク構築' => 'NW構築',
            // データ・AI
            'AI' => 'AI駆動開発、AI活用、人工知能', 'データ分析' => 'データアナリティクス、データ解析', '機械学習' => 'Machine Learning、ML、マシンラーニング', '深層学習' => 'ディープラーニング、Deep Learning、DL', '生成AI' => '生成 AI、Generative AI、GenAI、ジェネレーティブAI',
            'RAG' => 'Retrieval Augmented Generation、検索拡張生成', 'プロンプトエンジニアリング' => 'Prompt Engineering、プロンプト設計', 'NLP' => '自然言語処理', '画像認識' => '画像処理、画像解析', 'ETL' => 'ELT、データ連携基盤',
            'データウェアハウス' => 'DWH', 'データレイク' => 'Data Lake', 'BI' => 'ビーアイ、BIツール', 'ビッグデータ' => 'Big Data', 'IoT' => 'アイオーティー', '組込み開発' => '組み込み開発、組込開発、組込み、組み込み、組込、ファームウェア開発', 'RPA' => 'ロボティックプロセスオートメーション',
            'ブロックチェーン' => 'Blockchain',
            // 管理
            'マネジメント' => '工程管理、進捗管理、プロジェクト管理、プロジェクトマネジメント', '課題管理' => '課題管理表', '品質管理' => '品質保証、QA、品質向上', 'リスク管理' => '', '要員管理' => 'メンバー管理、チームマネジメント、メンバーマネジメント',
            'ベンダーコントロール' => 'ベンダー管理、ベンダー調整', 'PMBOK' => '', 'EVM' => 'アーンドバリューマネジメント', '顧客折衝' => '客先折衝、折衝、顧客調整、顧客対応、顧客コミュニケーション', 'ドキュメント作成' => '資料作成、設計書作成、ドキュメンテーション',
            '手順書作成' => '手順書、マニュアル作成', '教育・育成' => '新人教育、後輩育成、メンタリング、メンター、技術指導', '採用面談' => '面接官、技術面接',
        ],
        'domain' => [
            '金融' => 'FinTech、フィンテック', '証券' => '株式、トレーディング、証券会社', '保険' => '生保、損保、生命保険、損害保険', '銀行' => 'メガバンク、地方銀行、地銀', 'クレジットカード' => 'カード、カード会社', '決済' => '決済システム、キャッシュレス',
            '物流' => '倉庫、倉庫管理、WMS、配送', '小売' => '流通', 'EC' => 'ECサイト、通販、ネットショップ、オンラインショップ', '建設' => '建築、土木', '製造業' => 'メーカー', '生産管理' => '製造管理、MES',
            '医療' => '病院、医療機器、電子カルテ', '介護・福祉' => '介護、福祉、ヘルスケア、ヘルステック', '製薬' => '医薬品、創薬', '官公庁' => '中央省庁、自治体、公共、行政、官公庁系', '不動産' => '賃貸', '飲食' => '外食、レストラン', '旅行' => '宿泊、観光、トラベル',
            '通信' => 'キャリア、通信キャリア、モバイル通信', 'エネルギー' => 'ガス、電力', '教育' => 'eラーニング、教育系、EdTech', 'POS' => 'POSシステム', '予約システム' => '予約', '顧客管理' => 'CRM、会員管理、顧客情報管理',
            '人事' => 'HR、HRテック、人事システム、人事給与', '勤怠管理' => '勤怠、タイムカード', '会計' => '財務会計、経理、経理システム', '販売管理' => '在庫管理、受発注、受注管理', '基幹システム' => '基幹、基幹業務、社内システム',
            '自動車' => '車載、車載システム、自動運転、カーナビ', '航空・宇宙' => '航空、宇宙', '鉄道・交通' => '鉄道、交通、交通系', '広告' => 'アドテク、広告配信、広告代理店', 'ゲーム' => 'ゲーム開発、ソーシャルゲーム、スマホゲーム、家庭用ゲーム',
            'メディア' => '動画配信、メディア系、配信サービス', '半導体' => '', '家電' => '', 'ロボット' => 'ロボティクス', '農業' => 'アグリテック', 'SaaS' => 'サース', '自社開発' => '自社サービス、自社プロダクト', '受託開発' => '受託',
            'パッケージ開発' => 'パッケージ', 'Webサービス' => 'Webアプリ、Webシステム、Webアプリケーション', 'スマホアプリ' => 'モバイルアプリ、モバイル開発、スマートフォンアプリ', '業務システム' => '業務アプリ、業務アプリケーション',
        ],
        'role' => [
            'PM' => 'プロジェクトマネージャー、プロジェクトマネージャ、プロマネ', 'PL' => 'プロジェクトリーダー、プロジェクトリーダ、リーダー、リーダ、チームリーダー、チームリーダ', 'SE' => 'システムエンジニア',
            'PG' => 'プログラマー、プログラマ', 'PMO' => 'プロジェクトマネジメントオフィス', 'アーキテクト' => 'Architect、システムアーキテクト、ソリューションアーキテクト', 'テックリード' => 'Tech Lead、技術リーダー、技術リード',
            'スクラムマスター' => 'Scrum Master、ScrumMaster', 'プロダクトオーナー' => 'Product Owner、PO', 'プロダクトマネージャー' => 'Product Manager、プロダクトマネージャ',
            'エンジニアリングマネージャー' => 'EM、エンジニアリングマネージャ、開発マネージャー、開発マネージャ', 'インフラエンジニア' => 'Infrastructure Engineer、インフラ担当', 'ネットワークエンジニア' => 'NWエンジニア',
            'サーバーエンジニア' => 'サーバエンジニア、サーバ担当', 'クラウドエンジニア' => 'Cloud Engineer', 'フロントエンドエンジニア' => 'フロントエンド、Frontend、フロントエンド開発', 'バックエンドエンジニア' => 'バックエンド、Backend、バックエンド開発',
            'フルスタックエンジニア' => 'フルスタック、Full Stack', 'iOSエンジニア' => 'iOSアプリ開発、iPhoneアプリ開発', 'Androidエンジニア' => 'Androidアプリ開発', 'データエンジニア' => 'Data Engineer', 'データサイエンティスト' => 'Data Scientist',
            'AIエンジニア' => 'AI開発、機械学習エンジニア、MLエンジニア', 'QAエンジニア' => 'QA担当、品質保証エンジニア', 'テストエンジニア' => 'テスター、テスト担当、テストリーダー', 'SRE' => 'Site Reliability Engineer', 'DevOpsエンジニア' => '',
            'セキュリティエンジニア' => 'セキュリティ担当', 'DBA' => 'データベース管理者、データベースエンジニア、データベースアドミニストレータ', 'ITコンサルタント' => 'コンサルタント、コンサル、ITコンサル', 'ブリッジSE' => 'BSE、ブリッジエンジニア',
            '社内SE' => '情シス、情報システム部門', 'システム管理者' => 'シスアド、システム運用管理者', 'オペレーター' => '運用オペレーター、監視オペレーター', 'Webデザイナー' => 'UIデザイナー、デザイナー', 'Webディレクター' => 'ディレクター',
            'ヘルプデスク担当' => '', 'CTO' => '最高技術責任者',
        ],
        'qual' => [
            '基本情報技術者' => '基本情報、FE試験、基本情報技術者試験', '応用情報技術者' => '応用情報、AP試験、応用情報技術者試験', 'ITパスポート' => 'iパス、ITパス', '情報セキュリティマネジメント試験' => 'SG試験、情報セキュリティマネジメント',
            '高度情報処理技術者' => '高度情報、高度試験', 'プロジェクトマネージャ試験' => 'PM試験、プロジェクトマネージャ試験合格', 'ネットワークスペシャリスト' => 'ネスペ、NW試験', 'データベーススペシャリスト' => 'DBスペシャリスト、DB試験、データスペシャリスト',
            'システムアーキテクト試験' => 'SA試験', 'ITストラテジスト' => 'ST試験', 'エンベデッドシステムスペシャリスト' => 'エンベデッド、ES試験', '情報処理安全確保支援士' => 'セキスペ、情報セキュリティスペシャリスト、登録セキスペ', 'システム監査技術者' => 'AU試験',
            'ITサービスマネージャ' => 'SM試験', 'PMP' => 'Project Management Professional', 'ORACLE MASTER' => 'オラクルマスター、Oracle Master', 'Java認定資格' => 'Java Silver、Java Gold、Oracle認定Javaプログラマ、Javaプログラマ',
            'LPIC' => 'LinuC、LPI認定', 'CCNA' => 'CCNP、Cisco認定', 'AWS認定' => 'AWS Certified、AWS SAA、AWS認定ソリューションアーキテクト', 'Azure認定' => 'AZ-900、AZ-104、Microsoft認定', 'Google Cloud認定' => 'Google Cloud Certified',
            'Salesforce認定' => 'Salesforce認定資格、Salesforce認定アドミニストレータ', '認定スクラムマスター' => 'CSM、PSM、Professional Scrum Master', 'ISTQB' => 'JSTQB', 'TOEIC' => '', '日本語能力試験' => 'JLPT、日本語能力検定',
        ],
    ];

    // 文章から探すときに使わない言葉（辞書には残す。言葉そのものを調べる find では使う）。普通の英単語・名前と区別がつかないもの
    const NO_DETECT = ['Julia', 'Crystal', 'Zig', 'Nim', 'Echo', 'Gin', 'Chef', 'Puppet', 'Remix', 'Teams', 'Glue', 'Sketch', 'Cursor', 'Rocket', 'Phoenix', 'Compose', 'Vapor', 'Expo', 'Bun', 'Astro',
        'Natural', 'Prettier', 'Notion', 'Eloquent', 'Tornado', 'Celery', 'Ember', 'Backbone', 'Camel', 'Exchange', 'Groovy', 'Packer', 'Ladder', 'Claude', 'Gemini', 'Aurora', 'Less', 'Domo', 'Looker', 'Kong', 'Envoy', 'Okta', 'Lombok'];
}
