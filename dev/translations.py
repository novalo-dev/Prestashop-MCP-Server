#!/usr/bin/env python3
# Builds the four XLF files from one table, so that a string can never exist in
# one language and be missing from another.
#
# Usage: python3 dev/translations.py
# Add the English string as the key and its French, Spanish and German forms as
# the tuple, run the script, commit the table and the regenerated files together.
# Never edit the XLF files by hand: the next run overwrites them.
import hashlib, html, json, os

T = {
"O'So MCP Server": (
 "O'So MCP Server",
 "O'So MCP Server",
 "O'So MCP Server"),
"Lets AI assistants such as Claude, ChatGPT or Cursor connect to this shop, with each employee approving what they may do.": (
 "Permet aux assistants IA comme Claude, ChatGPT ou Cursor de se connecter à cette boutique, chaque employé validant ce qu'ils ont le droit de faire.",
 "Permite que asistentes de IA como Claude, ChatGPT o Cursor se conecten a esta tienda, y cada empleado aprueba lo que pueden hacer.",
 "Ermöglicht KI-Assistenten wie Claude, ChatGPT oder Cursor, sich mit diesem Shop zu verbinden, wobei jeder Mitarbeiter freigibt, was sie tun dürfen."),
"Uninstalling deletes every connected assistant, approval and token. Assistants connected right now lose their access immediately. Continue?": (
 "La désinstallation supprime tous les assistants connectés, les autorisations et les jetons. Les assistants connectés en ce moment perdent leur accès immédiatement. Continuer ?",
 "La desinstalación elimina todos los asistentes conectados, las autorizaciones y los tokens. Los asistentes conectados ahora mismo pierden su acceso de inmediato. ¿Continuar?",
 "Bei der Deinstallation werden alle verbundenen Assistenten, Freigaben und Token gelöscht. Aktuell verbundene Assistenten verlieren sofort ihren Zugang. Fortfahren?"),
"The %hook% hook could not be registered, and the module cannot answer without it.": (
 "Le hook %hook% n'a pas pu être enregistré, et le module ne peut pas répondre sans lui.",
 "No se ha podido registrar el hook %hook%, y el módulo no puede responder sin él.",
 "Der Hook %hook% konnte nicht registriert werden, und ohne ihn kann das Modul nicht antworten."),
"The module tables could not be created.": (
 "Les tables du module n'ont pas pu être créées.",
 "No se han podido crear las tablas del módulo.",
 "Die Tabellen des Moduls konnten nicht angelegt werden."),
"The module pages could not be installed.": (
 "Les pages du module n'ont pas pu être installées.",
 "No se han podido instalar las páginas del módulo.",
 "Die Seiten des Moduls konnten nicht installiert werden."),
"The assistant has been disconnected.": (
 "L'assistant a été déconnecté.",
 "El asistente ha sido desconectado.",
 "Der Assistent wurde getrennt."),
"The log has been emptied.": (
 "Le journal a été vidé.",
 "El registro se ha vaciado.",
 "Das Protokoll wurde geleert."),
"Settings saved.": (
 "Réglages enregistrés.",
 "Ajustes guardados.",
 "Einstellungen gespeichert."),
"Nothing to approve": (
 "Rien à autoriser",
 "Nada que autorizar",
 "Nichts freizugeben"),
"This connection request has expired or has already been answered. Start the connection again from your assistant.": (
 "Cette demande de connexion a expiré ou a déjà reçu une réponse. Relancez la connexion depuis votre assistant.",
 "Esta solicitud de conexión ha caducado o ya ha sido respondida. Vuelva a iniciar la conexión desde su asistente.",
 "Diese Verbindungsanfrage ist abgelaufen oder wurde bereits beantwortet. Starten Sie die Verbindung erneut aus Ihrem Assistenten."),
"The application behind this request is no longer known to the shop.": (
 "L'application à l'origine de cette demande n'est plus connue de la boutique.",
 "La aplicación que originó esta solicitud ya no es conocida por la tienda.",
 "Die Anwendung hinter dieser Anfrage ist dem Shop nicht mehr bekannt."),
"Connect an assistant": (
 "Connecter un assistant",
 "Conectar un asistente",
 "Einen Assistenten verbinden"),
"Your account is not allowed to connect assistants to this shop. Ask an administrator to change the required profile in the module settings.": (
 "Votre compte n'a pas le droit de connecter un assistant à cette boutique. Demandez à un administrateur de modifier le profil requis dans les réglages du module.",
 "Su cuenta no tiene permiso para conectar asistentes a esta tienda. Pida a un administrador que cambie el perfil requerido en los ajustes del módulo.",
 "Ihr Konto darf keine Assistenten mit diesem Shop verbinden. Bitten Sie einen Administrator, das erforderliche Profil in den Moduleinstellungen zu ändern."),
"Identified by the description it publishes at that address": (
 "Identifiée par la description qu'elle publie à cette adresse",
 "Identificada por la descripción que publica en esa dirección",
 "Identifiziert durch die Beschreibung, die sie unter dieser Adresse veröffentlicht"),
"Registered by hand in the module settings": (
 "Enregistrée à la main dans les réglages du module",
 "Registrada a mano en los ajustes del módulo",
 "Von Hand in den Moduleinstellungen eingetragen"),
"Registered itself automatically": (
 "Enregistrée automatiquement par elle-même",
 "Registrada automáticamente por sí misma",
 "Hat sich selbst automatisch registriert"),
"This shop is not accepting connections from assistants right now.": (
 "Cette boutique n'accepte pas de connexion d'assistant pour le moment.",
 "Esta tienda no acepta conexiones de asistentes en este momento.",
 "Dieser Shop nimmt derzeit keine Verbindungen von Assistenten an."),
"This connection request is not valid, so nothing was sent back to the application that made it.": (
 "Cette demande de connexion n'est pas valide : rien n'a été renvoyé à l'application qui l'a émise.",
 "Esta solicitud de conexión no es válida, por lo que no se ha devuelto nada a la aplicación que la hizo.",
 "Diese Verbindungsanfrage ist ungültig, daher wurde nichts an die anfragende Anwendung zurückgesendet."),
"The module cannot find the administration area of this shop yet. Open the module settings once and save them, then try again.": (
 "Le module ne trouve pas encore l'administration de cette boutique. Ouvrez les réglages du module, enregistrez-les une fois, puis réessayez.",
 "El módulo aún no encuentra la administración de esta tienda. Abra los ajustes del módulo, guárdelos una vez y vuelva a intentarlo.",
 "Das Modul findet die Verwaltung dieses Shops noch nicht. Öffnen Sie einmal die Moduleinstellungen, speichern Sie sie und versuchen Sie es erneut."),
"Connect to this shop, see the list of available tools, and read your account details and the shop configuration": (
 "Se connecter à cette boutique, voir la liste des outils disponibles, et lire les informations de votre compte ainsi que la configuration de la boutique",
 "Conectarse a esta tienda, ver la lista de herramientas disponibles y leer los datos de su cuenta y la configuración de la tienda",
 "Sich mit diesem Shop verbinden, die Liste der verfügbaren Werkzeuge sehen und die Daten Ihres Kontos sowie die Shop-Konfiguration lesen"),
"Read the orders of this shop, and who placed them": (
 "Lire les commandes de cette boutique, et savoir qui les a passées",
 "Leer los pedidos de esta tienda y saber quién los realizó",
 "Die Bestellungen dieses Shops lesen und sehen, wer sie aufgegeben hat"),
"Change the orders of this shop, which can email your customers": (
 "Modifier les commandes de cette boutique, ce qui peut envoyer des e-mails à vos clients",
 "Modificar los pedidos de esta tienda, lo que puede enviar correos electrónicos a sus clientes",
 "Die Bestellungen dieses Shops ändern, wodurch E-Mails an Ihre Kunden gesendet werden können"),
"Read the catalogue of this shop: products, prices and categories": (
 "Lire le catalogue de cette boutique : produits, prix et catégories",
 "Leer el catálogo de esta tienda: productos, precios y categorías",
 "Den Katalog dieses Shops lesen: Produkte, Preise und Kategorien"),
"Change the catalogue of this shop: create, edit and delete products, create and edit categories, change prices and stock": (
 "Modifier le catalogue de cette boutique : créer, modifier et supprimer les produits, créer et modifier les catégories, modifier les prix et le stock",
 "Modificar el catálogo de esta tienda: crear, editar y eliminar productos, crear y editar categorías, cambiar precios y stock",
 "Den Katalog dieses Shops ändern: Produkte anlegen, bearbeiten und löschen, Kategorien anlegen und bearbeiten, Preise und Bestand ändern"),
"Read the stock of this shop: quantities of products and combinations": (
 "Lire le stock de cette boutique : quantités des produits et des déclinaisons",
 "Leer el stock de esta tienda: cantidades de los productos y de las combinaciones",
 "Den Bestand dieses Shops lesen: Mengen der Produkte und Kombinationen"),
"Change the stock of this shop: set the quantities of products and combinations, recorded as stock movements": (
 "Modifier le stock de cette boutique : régler les quantités des produits et des déclinaisons, enregistrées comme mouvements de stock",
 "Modificar el stock de esta tienda: ajustar las cantidades de los productos y de las combinaciones, registradas como movimientos de stock",
 "Den Bestand dieses Shops ändern: Mengen der Produkte und Kombinationen festlegen, als Lagerbewegungen erfasst"),
"Outgoing HTTP requests": (
 "Requêtes HTTP sortantes",
 "Peticiones HTTP salientes",
 "Ausgehende HTTP-Anfragen"),
"The cURL extension is available.": (
 "L'extension cURL est disponible.",
 "La extensión cURL está disponible.",
 "Die cURL-Erweiterung ist verfügbar."),
"The cURL extension is missing. The module cannot read the metadata document published by a client, so no assistant can connect.": (
 "L'extension cURL est absente. Le module ne peut pas lire le document de description publié par une application, donc aucun assistant ne peut se connecter.",
 "Falta la extensión cURL. El módulo no puede leer el documento de descripción publicado por una aplicación, así que ningún asistente puede conectarse.",
 "Die cURL-Erweiterung fehlt. Das Modul kann das von einer Anwendung veröffentlichte Beschreibungsdokument nicht lesen, daher kann sich kein Assistent verbinden."),
"Friendly URLs": (
 "URL simplifiées",
 "URL amigables",
 "Suchmaschinenfreundliche URLs"),
"Friendly URLs are enabled.": (
 "Les URL simplifiées sont activées.",
 "Las URL amigables están activadas.",
 "Suchmaschinenfreundliche URLs sind aktiviert."),
"Friendly URLs are disabled. PrestaShop then routes nothing outside index.php, so the discovery documents cannot be reached and no assistant can connect. Enable them in Shop parameters, Traffic and SEO.": (
 "Les URL simplifiées sont désactivées. PrestaShop ne route alors rien en dehors d'index.php : les documents de découverte sont injoignables et aucun assistant ne peut se connecter. Activez-les dans Paramètres de la boutique, Trafic et SEO.",
 "Las URL amigables están desactivadas. PrestaShop no enruta entonces nada fuera de index.php: los documentos de descubrimiento no son accesibles y ningún asistente puede conectarse. Actívelas en Parámetros de la tienda, Tráfico y SEO.",
 "Suchmaschinenfreundliche URLs sind deaktiviert. PrestaShop leitet dann nichts außerhalb von index.php weiter: Die Discovery-Dokumente sind nicht erreichbar und kein Assistent kann sich verbinden. Aktivieren Sie sie unter Shop-Parameter, Traffic und SEO."),
"Shop location": (
 "Emplacement de la boutique",
 "Ubicación de la tienda",
 "Speicherort des Shops"),
"This shop is installed in a subdirectory. The authorization server document has to sit at the root of the domain, which is outside the shop, so PrestaShop cannot serve it. Moving the shop to the root of a domain or a subdomain is the only way around it.": (
 "Cette boutique est installée dans un sous-répertoire. Le document du serveur d'autorisation doit se trouver à la racine du domaine, donc en dehors de la boutique : PrestaShop ne peut pas le servir. Déplacer la boutique à la racine d'un domaine ou d'un sous-domaine est la seule solution.",
 "Esta tienda está instalada en un subdirectorio. El documento del servidor de autorización debe estar en la raíz del dominio, es decir, fuera de la tienda, por lo que PrestaShop no puede servirlo. Mover la tienda a la raíz de un dominio o subdominio es la única solución.",
 "Dieser Shop ist in einem Unterverzeichnis installiert. Das Dokument des Autorisierungsservers muss im Stammverzeichnis der Domain liegen, also außerhalb des Shops, sodass PrestaShop es nicht ausliefern kann. Der Shop muss dafür in das Stammverzeichnis einer Domain oder Subdomain umziehen."),
"The shop sits at the root of its domain.": (
 "La boutique se trouve à la racine de son domaine.",
 "La tienda está en la raíz de su dominio.",
 "Der Shop liegt im Stammverzeichnis seiner Domain."),
"Consent screen": (
 "Écran d'autorisation",
 "Pantalla de autorización",
 "Freigabe-Bildschirm"),
"The consent screen can be reached from the authorization flow.": (
 "L'écran d'autorisation est joignable depuis le parcours de connexion.",
 "La pantalla de autorización es accesible desde el proceso de conexión.",
 "Der Freigabe-Bildschirm ist über den Verbindungsablauf erreichbar."),
"The administration folder is not known yet. Save the settings once and this resolves itself.": (
 "Le dossier d'administration n'est pas encore connu. Enregistrez les réglages une fois et le problème disparaît.",
 "La carpeta de administración aún no se conoce. Guarde los ajustes una vez y esto se resuelve solo.",
 "Der Verwaltungsordner ist noch nicht bekannt. Speichern Sie die Einstellungen einmal, dann erledigt sich das von selbst."),
"Authorization server document": (
 "Document du serveur d'autorisation",
 "Documento del servidor de autorización",
 "Dokument des Autorisierungsservers"),
"This address answered %status% instead of 200. Assistants read it to find out where to sign in, so none of them can connect until it works. Some hosting setups answer paths starting with .well-known themselves, before PrestaShop ever sees them; a rewrite rule sending that path to index.php fixes it.": (
 "Cette adresse a répondu %status% au lieu de 200. Les assistants la lisent pour savoir où se connecter : aucun ne pourra se connecter tant qu'elle ne fonctionne pas. Certains hébergements répondent eux-mêmes aux chemins commençant par .well-known, avant que PrestaShop ne les voie ; une règle de réécriture envoyant ce chemin vers index.php règle le problème.",
 "Esta dirección respondió %status% en lugar de 200. Los asistentes la leen para saber dónde iniciar sesión, así que ninguno podrá conectarse mientras no funcione. Algunos alojamientos responden ellos mismos a las rutas que empiezan por .well-known, antes de que PrestaShop las vea; una regla de reescritura que envíe esa ruta a index.php lo soluciona.",
 "Diese Adresse hat %status% statt 200 geantwortet. Assistenten lesen sie, um zu erfahren, wo sie sich anmelden sollen, daher kann sich keiner verbinden, solange sie nicht funktioniert. Manche Hosting-Umgebungen beantworten Pfade, die mit .well-known beginnen, selbst, bevor PrestaShop sie überhaupt sieht; eine Rewrite-Regel, die diesen Pfad an index.php weiterleitet, behebt das."),
"This address answered, but not with the expected document. Something else on the hosting is serving that path.": (
 "Cette adresse a répondu, mais pas avec le document attendu. Autre chose sur l'hébergement sert ce chemin.",
 "Esta dirección respondió, pero no con el documento esperado. Otra cosa en el alojamiento está sirviendo esa ruta.",
 "Diese Adresse hat geantwortet, aber nicht mit dem erwarteten Dokument. Etwas anderes auf dem Hosting liefert diesen Pfad aus."),
"Served correctly.": (
 "Servi correctement.",
 "Servido correctamente.",
 "Wird korrekt ausgeliefert."),
"Protected resource document": (
 "Document de la ressource protégée",
 "Documento del recurso protegido",
 "Dokument der geschützten Ressource"),
"This address answered %status% instead of the expected document.": (
 "Cette adresse a répondu %status% au lieu du document attendu.",
 "Esta dirección respondió %status% en lugar del documento esperado.",
 "Diese Adresse hat %status% statt des erwarteten Dokuments geantwortet."),
"MCP endpoint": (
 "Point d'entrée MCP",
 "Punto de entrada MCP",
 "MCP-Endpunkt"),
"The endpoint answered %status% where an unauthenticated call must answer 401.": (
 "Le point d'entrée a répondu %status% là où un appel non authentifié doit répondre 401.",
 "El punto de entrada respondió %status% donde una llamada no autenticada debe responder 401.",
 "Der Endpunkt hat %status% geantwortet, wo ein nicht authentifizierter Aufruf 401 antworten muss."),
"The endpoint answered 401 but without the challenge that tells an assistant where to sign in.": (
 "Le point d'entrée a répondu 401 mais sans l'en-tête qui indique à un assistant où se connecter.",
 "El punto de entrada respondió 401 pero sin la cabecera que indica al asistente dónde iniciar sesión.",
 "Der Endpunkt hat 401 geantwortet, aber ohne den Header, der einem Assistenten sagt, wo er sich anmelden soll."),
"The endpoint answers correctly, but the Authorization header never reached PHP: this hosting drops it. Assistants will always be treated as signed out. Adding a rewrite rule that copies the header into the environment fixes it.": (
 "Le point d'entrée répond correctement, mais l'en-tête Authorization n'est jamais arrivé jusqu'à PHP : cet hébergement le supprime. Les assistants seront toujours considérés comme déconnectés. Ajouter une règle de réécriture qui recopie l'en-tête dans l'environnement règle le problème.",
 "El punto de entrada responde correctamente, pero la cabecera Authorization nunca llegó a PHP: este alojamiento la elimina. Los asistentes siempre se considerarán desconectados. Añadir una regla de reescritura que copie la cabecera al entorno lo soluciona.",
 "Der Endpunkt antwortet korrekt, aber der Authorization-Header hat PHP nie erreicht: Dieses Hosting entfernt ihn. Assistenten gelten dadurch immer als abgemeldet. Eine Rewrite-Regel, die den Header in die Umgebung kopiert, behebt das."),
"Answers with the expected challenge, and credentials reach the module.": (
 "Répond avec l'en-tête attendu, et les identifiants arrivent bien jusqu'au module.",
 "Responde con la cabecera esperada, y las credenciales llegan al módulo.",
 "Antwortet mit dem erwarteten Header, und die Anmeldedaten erreichen das Modul."),
"An application is asking to connect to your shop on your behalf.": (
 "Une application demande à se connecter à votre boutique en votre nom.",
 "Una aplicación solicita conectarse a su tienda en su nombre.",
 "Eine Anwendung möchte sich in Ihrem Namen mit Ihrem Shop verbinden."),
"Application": (
 "Application",
 "Aplicación",
 "Anwendung"),
"Identifier": (
 "Identifiant",
 "Identificador",
 "Kennung"),
"Signed in as": (
 "Connecté en tant que",
 "Conectado como",
 "Angemeldet als"),
"Shop": (
 "Boutique",
 "Tienda",
 "Shop"),
"Will be sent back to": (
 "Sera renvoyé vers",
 "Será redirigido a",
 "Wird zurückgeleitet an"),
"What it will be able to do": (
 "Ce qu'elle pourra faire",
 "Lo que podrá hacer",
 "Was sie tun können wird"),
"The application never gets more rights than your own account. You can disconnect it at any time from the module settings.": (
 "L'application n'obtient jamais plus de droits que votre propre compte. Vous pouvez la déconnecter à tout moment depuis les réglages du module.",
 "La aplicación nunca obtiene más permisos que su propia cuenta. Puede desconectarla en cualquier momento desde los ajustes del módulo.",
 "Die Anwendung erhält nie mehr Rechte als Ihr eigenes Konto. Sie können sie jederzeit in den Moduleinstellungen trennen."),
"Refuse": (
 "Refuser",
 "Rechazar",
 "Ablehnen"),
"Allow the connection": (
 "Autoriser la connexion",
 "Permitir la conexión",
 "Verbindung erlauben"),
"Address to give to your assistant": (
 "Adresse à donner à votre assistant",
 "Dirección que debe dar a su asistente",
 "Adresse für Ihren Assistenten"),
"Paste this address into Claude, ChatGPT, Cursor or any other assistant that speaks MCP. It will ask you to sign in to this shop and to approve the connection.": (
 "Collez cette adresse dans Claude, ChatGPT, Cursor ou tout autre assistant qui parle MCP. Il vous demandera de vous connecter à cette boutique et d'autoriser la connexion.",
 "Pegue esta dirección en Claude, ChatGPT, Cursor o cualquier otro asistente que hable MCP. Le pedirá que inicie sesión en esta tienda y que autorice la conexión.",
 "Fügen Sie diese Adresse in Claude, ChatGPT, Cursor oder einen anderen Assistenten ein, der MCP spricht. Er wird Sie bitten, sich in diesem Shop anzumelden und die Verbindung freizugeben."),
"Authorization server:": (
 "Serveur d'autorisation :",
 "Servidor de autorización:",
 "Autorisierungsserver:"),
"current shop": (
 "boutique courante",
 "tienda actual",
 "aktueller Shop"),
"Each shop has its own address and its own connections. An assistant connected to one shop reaches that shop only.": (
 "Chaque boutique a sa propre adresse et ses propres connexions. Un assistant connecté à une boutique n'atteint que celle-là.",
 "Cada tienda tiene su propia dirección y sus propias conexiones. Un asistente conectado a una tienda solo alcanza esa.",
 "Jeder Shop hat seine eigene Adresse und seine eigenen Verbindungen. Ein mit einem Shop verbundener Assistent erreicht nur diesen."),
"Diagnostic": (
 "Diagnostic",
 "Diagnóstico",
 "Diagnose"),
"Shop enabled": (
 "Boutique activée",
 "Tienda activada",
 "Shop aktiviert"),
"This shop is enabled.": (
 "Cette boutique est activée.",
 "Esta tienda está activada.",
 "Dieser Shop ist aktiviert."),
"This shop is disabled in the multistore settings. It serves nothing at all, so no assistant can reach it. Enable it to use the MCP server on this shop.": (
 "Cette boutique est désactivée dans les réglages multiboutique. Elle ne sert rien du tout, donc aucun assistant ne peut l'atteindre. Activez-la pour utiliser le serveur MCP sur cette boutique.",
 "Esta tienda está desactivada en los ajustes multitienda. No sirve nada en absoluto, así que ningún asistente puede alcanzarla. Actívela para usar el servidor MCP en esta tienda.",
 "Dieser Shop ist in den Multishop-Einstellungen deaktiviert. Er liefert überhaupt nichts aus, daher kann ihn kein Assistent erreichen. Aktivieren Sie ihn, um den MCP-Server fuer diesen Shop zu nutzen."),
"Shop open": (
 "Boutique ouverte",
 "Tienda abierta",
 "Shop geöffnet"),
"This shop is open.": (
 "Cette boutique est ouverte.",
 "Esta tienda está abierta.",
 "Dieser Shop ist geöffnet."),
"This shop is in maintenance. Assistants still connect and work: the MCP address belongs to you, not to your visitors. Turn off the MCP server above if you want it closed too.": (
 "Cette boutique est en maintenance. Les assistants se connectent et fonctionnent quand même : l'adresse MCP est la vôtre, pas celle de vos visiteurs. Désactivez le serveur MCP ci-dessus si vous voulez le fermer aussi.",
 "Esta tienda está en mantenimiento. Los asistentes se conectan y funcionan igualmente: la dirección MCP es suya, no de sus visitantes. Desactive el servidor MCP arriba si quiere cerrarlo también.",
 "Dieser Shop ist im Wartungsmodus. Assistenten verbinden sich und arbeiten weiterhin: Die MCP-Adresse gehört Ihnen, nicht Ihren Besuchern. Schalten Sie den MCP-Server oben ab, wenn Sie ihn ebenfalls schließen möchten."),
"Checks one shop the way an assistant would: it calls that shop own addresses from the outside and reports what comes back.": (
 "Contrôle une boutique comme le ferait un assistant : les adresses propres à cette boutique sont appelées depuis l'extérieur et le résultat est affiché.",
 "Comprueba una tienda como lo haría un asistente: llama a las direcciones propias de esa tienda desde fuera y muestra lo que responden.",
 "Prüft einen Shop so, wie es ein Assistent tun würde: Die eigenen Adressen dieses Shops werden von außen aufgerufen und das Ergebnis wird angezeigt."),
"Run the diagnostic": (
 "Lancer le diagnostic",
 "Ejecutar el diagnóstico",
 "Diagnose starten"),
"Settings reserved for the shop administrator": (
 "Réglages réservés à l'administrateur de la boutique",
 "Ajustes reservados al administrador de la tienda",
 "Einstellungen dem Shop-Administrator vorbehalten"),
"Only an administrator of this shop can change the O'So MCP Server settings.": (
 "Les réglages d'O'So MCP Server sont modifiables uniquement par un administrateur de la boutique.",
 "Los ajustes de O'So MCP Server solo puede modificarlos un administrador de la tienda.",
 "Die Einstellungen von O'So MCP Server kann nur ein Administrator des Shops ändern."),
"Only an administrator of this shop can change the O'So MCP Server settings, and your account is not allowed to connect an assistant. Ask an administrator if you need one.": (
 "Les réglages d'O'So MCP Server sont modifiables uniquement par un administrateur de la boutique, et votre compte n'est pas autorisé à connecter un assistant. Demandez à un administrateur si vous en avez besoin.",
 "Los ajustes de O'So MCP Server solo puede modificarlos un administrador de la tienda, y su cuenta no está autorizada a conectar un asistente. Pídaselo a un administrador si lo necesita.",
 "Die Einstellungen von O'So MCP Server kann nur ein Administrator des Shops ändern, und Ihr Konto darf keinen Assistenten verbinden. Wenden Sie sich an einen Administrator, wenn Sie einen benötigen."),
"Copy": (
 "Copier",
 "Copiar",
 "Kopieren"),
"Copied": (
 "Copié",
 "Copiado",
 "Kopiert"),
"Your account may connect an assistant. Paste this address into it, then approve the connection when the shop asks you to sign in.": (
 "Votre compte peut connecter un assistant. Collez cette adresse dedans, puis autorisez la connexion quand la boutique vous demandera de vous identifier.",
 "Su cuenta puede conectar un asistente. Pegue esta dirección en él y autorice la conexión cuando la tienda le pida identificarse.",
 "Ihr Konto darf einen Assistenten verbinden. Fügen Sie diese Adresse dort ein und geben Sie die Verbindung frei, wenn der Shop Sie zur Anmeldung auffordert."),
"Settings": (
 "Réglages",
 "Ajustes",
 "Einstellungen"),
"MCP server": (
 "Serveur MCP",
 "Servidor MCP",
 "MCP-Server"),
"Enable the MCP server": (
 "Activer le serveur MCP",
 "Activar el servidor MCP",
 "MCP-Server aktivieren"),
"Yes": (
 "Oui",
 "Sí",
 "Ja"),
"No": (
 "Non",
 "No",
 "Nein"),
"Turning this off closes the endpoint immediately. Tokens already issued stop working.": (
 "Désactiver ferme le point d'entrée immédiatement. Les jetons déjà émis cessent de fonctionner.",
 "Desactivarlo cierra el punto de entrada de inmediato. Los tokens ya emitidos dejan de funcionar.",
 "Das Deaktivieren schließt den Endpunkt sofort. Bereits ausgegebene Token funktionieren nicht mehr."),
"Employees allowed to connect an assistant": (
 "Employés autorisés à connecter un assistant",
 "Empleados autorizados a conectar un asistente",
 "Mitarbeiter, die einen Assistenten verbinden dürfen"),
"Any employee": (
 "Tous les employés",
 "Cualquier empleado",
 "Alle Mitarbeiter"),
"An assistant never gets more rights than the employee who connected it.": (
 "Un assistant n'obtient jamais plus de droits que l'employé qui l'a connecté.",
 "Un asistente nunca obtiene más permisos que el empleado que lo conectó.",
 "Ein Assistent erhält nie mehr Rechte als der Mitarbeiter, der ihn verbunden hat."),
"OAuth 2.1": (
 "OAuth 2.1",
 "OAuth 2.1",
 "OAuth 2.1"),
"Accept new connections": (
 "Accepter les nouvelles connexions",
 "Aceptar nuevas conexiones",
 "Neue Verbindungen annehmen"),
"Access token lifetime (seconds)": (
 "Durée de vie du jeton d'accès (secondes)",
 "Duración del token de acceso (segundos)",
 "Gültigkeitsdauer des Zugriffstokens (Sekunden)"),
"Refresh token lifetime (seconds)": (
 "Durée de vie du jeton de renouvellement (secondes)",
 "Duración del token de renovación (segundos)",
 "Gültigkeitsdauer des Erneuerungstokens (Sekunden)"),
"How long an assistant can keep reconnecting on its own before the merchant has to approve it again.": (
 "Durée pendant laquelle un assistant peut se reconnecter tout seul avant que le marchand doive l'autoriser à nouveau.",
 "Tiempo durante el cual un asistente puede reconectarse solo antes de que el comerciante deba autorizarlo de nuevo.",
 "Zeitraum, in dem sich ein Assistent selbstständig wieder verbinden kann, bevor der Händler ihn erneut freigeben muss."),
"Replace the refresh token on every use": (
 "Remplacer le jeton de renouvellement à chaque utilisation",
 "Sustituir el token de renovación en cada uso",
 "Erneuerungstoken bei jeder Nutzung ersetzen"),
"Recommended. A token that is used twice then reveals a theft, and the shop cuts the whole connection.": (
 "Recommandé. Un jeton utilisé deux fois révèle alors un vol, et la boutique coupe toute la connexion.",
 "Recomendado. Un token usado dos veces revela entonces un robo, y la tienda corta toda la conexión.",
 "Empfohlen. Ein zweimal verwendetes Token verrät dann einen Diebstahl, und der Shop trennt die gesamte Verbindung."),
"Value of its own here": (
 "Valeur propre à ce niveau",
 "Valor propio en este nivel",
 "Eigener Wert auf dieser Ebene"),
"inherited from the group": (
 "héritée du groupe",
 "heredado del grupo",
 "von der Gruppe geerbt"),
"inherited from all shops": (
 "héritée de toutes les boutiques",
 "heredado de todas las tiendas",
 "von allen Shops geerbt"),
"module default": (
 "valeur par défaut du module",
 "valor por defecto del módulo",
 "Standardwert des Moduls"),
"Security": (
 "Sécurité",
 "Seguridad",
 "Sicherheit"),
"Let applications identify themselves by address": (
 "Laisser les applications s'identifier par leur adresse",
 "Permitir que las aplicaciones se identifiquen por su dirección",
 "Anwendungen erlauben, sich über ihre Adresse auszuweisen"),
"This is how the Claude assistants connect. Turning it off will stop them from working.": (
 "C'est ainsi que les assistants Claude se connectent. Désactiver les empêchera de fonctionner.",
 "Así es como se conectan los asistentes Claude. Desactivarlo impedirá que funcionen.",
 "So verbinden sich die Claude-Assistenten. Ein Deaktivieren verhindert, dass sie funktionieren."),
"Let applications register themselves": (
 "Laisser les applications s'enregistrer elles-mêmes",
 "Permitir que las aplicaciones se registren por sí mismas",
 "Anwendungen erlauben, sich selbst zu registrieren"),
"Older way of connecting, kept for assistants that know nothing else. Registering costs nothing on its own: an application still has to be approved by an employee before it can do anything.": (
 "Ancienne manière de se connecter, conservée pour les assistants qui ne connaissent que celle-là. S'enregistrer ne donne rien en soi : une application doit toujours être autorisée par un employé avant de pouvoir agir.",
 "Forma antigua de conectarse, mantenida para los asistentes que solo conocen esa. Registrarse no da nada por sí mismo: una aplicación todavía debe ser autorizada por un empleado antes de poder actuar.",
 "Ältere Art der Verbindung, beibehalten für Assistenten, die nichts anderes kennen. Die Registrierung allein bewirkt nichts: Eine Anwendung muss weiterhin von einem Mitarbeiter freigegeben werden, bevor sie etwas tun kann."),
"Only allow these return addresses": (
 "N'autoriser que ces adresses de retour",
 "Permitir solo estas direcciones de retorno",
 "Nur diese Rückleitungsadressen zulassen"),
"Host names separated by spaces, for example claude.ai chatgpt.com. Leave empty to accept any address an application registers. Desktop assistants use your own computer and are always allowed.": (
 "Noms de domaine séparés par des espaces, par exemple claude.ai chatgpt.com. Laissez vide pour accepter toute adresse enregistrée par une application. Les assistants de bureau passent par votre propre ordinateur et sont toujours autorisés.",
 "Nombres de dominio separados por espacios, por ejemplo claude.ai chatgpt.com. Déjelo vacío para aceptar cualquier dirección registrada por una aplicación. Los asistentes de escritorio usan su propio ordenador y siempre están permitidos.",
 "Hostnamen durch Leerzeichen getrennt, zum Beispiel claude.ai chatgpt.com. Leer lassen, um jede von einer Anwendung eingetragene Adresse zu akzeptieren. Desktop-Assistenten laufen über Ihren eigenen Rechner und sind immer erlaubt."),
"Keep a security log": (
 "Tenir un journal de sécurité",
 "Mantener un registro de seguridad",
 "Ein Sicherheitsprotokoll führen"),
"Connections, approvals and refusals, kept for thirty days. Never contains a token.": (
 "Connexions, autorisations et refus, conservés trente jours. Ne contient jamais de jeton.",
 "Conexiones, autorizaciones y rechazos, conservados durante treinta días. Nunca contiene un token.",
 "Verbindungen, Freigaben und Ablehnungen, dreißig Tage lang aufbewahrt. Enthält nie ein Token."),
"Save": (
 "Enregistrer",
 "Guardar",
 "Speichern"),
"Connected assistants": (
 "Assistants connectés",
 "Asistentes conectados",
 "Verbundene Assistenten"),
"Approved by": (
 "Autorisé par",
 "Autorizado por",
 "Freigegeben von"),
"Profile": (
 "Profil",
 "Perfil",
 "Profil"),
"account disabled": (
 "compte désactivé",
 "cuenta desactivada",
 "Konto deaktiviert"),
"unknown": (
 "inconnu",
 "desconocido",
 "unbekannt"),
"Permissions": (
 "Permissions",
 "Permisos",
 "Berechtigungen"),
"Since": (
 "Depuis",
 "Desde",
 "Seit"),
"Disconnect": (
 "Déconnecter",
 "Desconectar",
 "Trennen"),
"No assistant is connected to this shop yet.": (
 "Aucun assistant n'est encore connecté à cette boutique.",
 "Todavía no hay ningún asistente conectado a esta tienda.",
 "Es ist noch kein Assistent mit diesem Shop verbunden."),
"Security log": (
 "Journal de sécurité",
 "Registro de seguridad",
 "Sicherheitsprotokoll"),
"Date": (
 "Date",
 "Fecha",
 "Datum"),
"Event": (
 "Événement",
 "Evento",
 "Ereignis"),
"Detail": (
 "Détail",
 "Detalle",
 "Detail"),
"Empty the log": (
 "Vider le journal",
 "Vaciar el registro",
 "Protokoll leeren"),
"Nothing logged yet.": (
 "Rien n'a encore été journalisé.",
 "Todavía no se ha registrado nada.",
 "Es wurde noch nichts protokolliert."),
"Available tools": (
 "Outils disponibles",
 "Herramientas disponibles",
 "Verfügbare Werkzeuge"),
"What an assistant may ask this shop to do. A tool switched off here is offered to nobody, whatever permissions their account holds. Tools published by another module carry that module name in front of theirs.": (
 "Ce qu'un assistant peut demander à cette boutique. Un outil désactivé ici n'est proposé à personne, quels que soient les droits de son compte. Les outils publiés par un autre module portent le nom de ce module devant le leur.",
 "Lo que un asistente puede pedir a esta tienda. Una herramienta desactivada aquí no se ofrece a nadie, sean cuales sean los permisos de su cuenta. Las herramientas publicadas por otro módulo llevan delante el nombre de ese módulo.",
 "Was ein Assistent von diesem Shop verlangen darf. Ein hier ausgeschaltetes Werkzeug wird niemandem angeboten, unabhängig von den Rechten seines Kontos. Werkzeuge anderer Module tragen deren Modulnamen vor dem eigenen."),
"On": (
 "Actif",
 "Activo",
 "Aktiv"),
"Tool": (
 "Outil",
 "Herramienta",
 "Werkzeug"),
"Published by": (
 "Publié par",
 "Publicado por",
 "Veröffentlicht von"),
"Requires": (
 "Exige",
 "Requiere",
 "Erfordert"),
"changes data": (
 "modifie des données",
 "modifica datos",
 "ändert Daten"),
"This module": (
 "Ce module",
 "Este módulo",
 "Dieses Modul"),
"no particular permission": (
 "aucun droit particulier",
 "ningún permiso concreto",
 "keine besondere Berechtigung"),
"No tool is available on this shop.": (
 "Aucun outil n'est disponible sur cette boutique.",
 "No hay ninguna herramienta disponible en esta tienda.",
 "Auf diesem Shop ist kein Werkzeug verfügbar."),
"Get an order": (
 "Lire une commande",
 "Leer un pedido",
 "Eine Bestellung abrufen"),
"List order statuses": (
 "Lister les statuts de commande",
 "Listar los estados de pedido",
 "Bestellstatus auflisten"),
"Test the connection": (
 "Tester la connexion",
 "Probar la conexión",
 "Die Verbindung testen"),
"Search orders": (
 "Rechercher des commandes",
 "Buscar pedidos",
 "Bestellungen suchen"),
"List categories": (
 "Lister les catégories",
 "Listar las categorías",
 "Kategorien auflisten"),
"List brands": (
 "Lister les marques",
 "Listar las marcas",
 "Marken auflisten"),
"List suppliers": (
 "Lister les fournisseurs",
 "Listar los proveedores",
 "Lieferanten auflisten"),
"List attributes": (
 "Lister les attributs",
 "Listar los atributos",
 "Attribute auflisten"),
"List features": (
 "Lister les caractéristiques",
 "Listar las características",
 "Merkmale auflisten"),
"Search products": (
 "Rechercher des produits",
 "Buscar productos",
 "Produkte suchen"),
"Get a product": (
 "Lire un produit",
 "Leer un producto",
 "Ein Produkt abrufen"),
"Create a product": (
 "Créer un produit",
 "Crear un producto",
 "Ein Produkt anlegen"),
"Change a product": (
 "Modifier un produit",
 "Modificar un producto",
 "Ein Produkt ändern"),
"Switch a product on or off": (
 "Activer ou désactiver un produit",
 "Activar o desactivar un producto",
 "Ein Produkt aktivieren oder deaktivieren"),
"Delete a product": (
 "Supprimer un produit",
 "Eliminar un producto",
 "Ein Produkt löschen"),
"Create a category": (
 "Créer une catégorie",
 "Crear una categoría",
 "Eine Kategorie anlegen"),
"Change a category": (
 "Modifier une catégorie",
 "Modificar una categoría",
 "Eine Kategorie ändern"),
"Get the stock of a product": (
 "Lire le stock d'un produit",
 "Leer el stock de un producto",
 "Den Bestand eines Produkts abrufen"),
"Set the stock of a product": (
 "Régler le stock d'un produit",
 "Ajustar el stock de un producto",
 "Den Bestand eines Produkts festlegen"),
"List low stock": (
 "Lister les stocks bas",
 "Listar el stock bajo",
 "Niedrigen Bestand auflisten"),
"Change the status of an order": (
 "Changer le statut d'une commande",
 "Cambiar el estado de un pedido",
 "Den Status einer Bestellung ändern"),
"view": (
 "voir",
 "ver",
 "ansehen"),
"add": (
 "ajouter",
 "añadir",
 "hinzufügen"),
"edit": (
 "modifier",
 "modificar",
 "bearbeiten"),
"delete": (
 "supprimer",
 "eliminar",
 "löschen"),
"Previous": (
 "Précédent",
 "Anterior",
 "Zurück"),
"Next": (
 "Suivant",
 "Siguiente",
 "Weiter"),
"Tools %from% to %to% of %total%": (
 "Outils %from% à %to% sur %total%",
 "Herramientas %from% a %to% de %total%",
 "Werkzeuge %from% bis %to% von %total%"),
"How long an assistant keeps the list of tools": (
 "Durée pendant laquelle un assistant garde la liste des outils",
 "Tiempo durante el cual un asistente conserva la lista de herramientas",
 "Wie lange ein Assistent die Werkzeugliste behält"),
"In seconds, between 10 and 3600. This shop cannot tell an assistant that the list changed - there is no connection open to it - so the assistant re-reads only when this has run out. A tool switched on or off appears or disappears after that delay, not before. Lower it while you are adding tools, raise it back afterwards. 300 by default.": (
 "En secondes, entre 10 et 3600. Cette boutique ne peut pas prévenir un assistant que la liste a changé - aucune connexion ne reste ouverte vers lui - donc il ne la relit qu'une fois ce délai écoulé. Un outil activé ou désactivé apparaît ou disparaît après ce délai, pas avant. Abaissez-le pendant que vous ajoutez des outils, remontez-le ensuite. 300 par défaut.",
 "En segundos, entre 10 y 3600. Esta tienda no puede avisar a un asistente de que la lista ha cambiado - no hay ninguna conexión abierta hacia él - así que solo la vuelve a leer cuando este plazo termina. Una herramienta activada o desactivada aparece o desaparece después de ese plazo, no antes. Redúzcalo mientras añade herramientas y vuelva a subirlo después. 300 por defecto.",
 "In Sekunden, zwischen 10 und 3600. Dieser Shop kann einem Assistenten nicht mitteilen, dass sich die Liste geändert hat - es besteht keine offene Verbindung zu ihm - daher liest er sie erst nach Ablauf dieser Zeit erneut. Ein ein- oder ausgeschaltetes Werkzeug erscheint oder verschwindet erst danach. Senken Sie den Wert beim Hinzufügen von Werkzeugen und erhöhen Sie ihn danach wieder. Standard 300."),
"Available prompts": (
 "Prompts disponibles",
 "Prompts disponibles",
 "Verfügbare Prompts"),
"Prompt": (
 "Prompt",
 "Prompt",
 "Prompt"),
"Asks for": (
 "Demande",
 "Solicita",
 "Fragt nach"),
"nothing": (
 "rien",
 "nada",
 "nichts"),
"Prompts %from% to %to% of %total%": (
 "Prompts %from% à %to% sur %total%",
 "Prompts %from% a %to% de %total%",
 "Prompts %from% bis %to% von %total%"),
"No prompt is available on this shop.": (
 "Aucun prompt n'est disponible sur cette boutique.",
 "No hay ningún prompt disponible en esta tienda.",
 "Auf diesem Shop ist kein Prompt verfügbar."),
"A prompt is a guided task you start yourself from your assistant menu. It costs nothing until you pick it, then hands the assistant step-by-step instructions for using the tools. A prompt published by another module puts that module words into your assistant, which is why you can switch each one off here.": (
 "Un prompt est une tâche guidée que vous lancez vous-même depuis le menu de votre assistant. Il ne coûte rien tant que vous ne le choisissez pas, puis il donne à l'assistant les instructions pas à pas pour utiliser les outils. Un prompt publié par un autre module place les mots de ce module dans votre assistant, et c'est pourquoi vous pouvez désactiver chacun ici.",
 "Un prompt es una tarea guiada que usted mismo inicia desde el menú de su asistente. No cuesta nada mientras no lo elija; después da al asistente las instrucciones paso a paso para usar las herramientas. Un prompt publicado por otro módulo coloca las palabras de ese módulo en su asistente, y por eso puede desactivar cada uno aquí.",
 "Ein Prompt ist eine geführte Aufgabe, die Sie selbst aus dem Menü Ihres Assistenten starten. Er kostet nichts, solange Sie ihn nicht auswählen, und gibt dem Assistenten dann Schritt-für-Schritt-Anweisungen zur Nutzung der Werkzeuge. Ein von einem anderen Modul veröffentlichter Prompt legt die Worte dieses Moduls in Ihren Assistenten, weshalb Sie jeden einzelnen hier ausschalten können."),
"Check the shop connection": (
 "Vérifier la connexion à la boutique",
 "Comprobar la conexión con la tienda",
 "Verbindung zum Shop prüfen"),
"Find an order": (
 "Trouver une commande",
 "Buscar un pedido",
 "Eine Bestellung finden"),
"Change an order status": (
 "Changer le statut d'une commande",
 "Cambiar el estado de un pedido",
 "Den Status einer Bestellung ändern"),
"Manage products": (
 "Gérer les produits",
 "Gestionar los productos",
 "Produkte verwalten"),
"Check and adjust stock": (
 "Vérifier et ajuster le stock",
 "Comprobar y ajustar el stock",
 "Bestand prüfen und anpassen"),
"Check that the connection to the PrestaShop shop works, and report what it reached - which shop, which account, which language and currency the answers are in.": (
 "Vérifier que la connexion à la boutique PrestaShop fonctionne, et indiquer ce qu'elle atteint : quelle boutique, quel compte, dans quelle langue et quelle devise arrivent les réponses.",
 "Comprobar que la conexión con la tienda PrestaShop funciona e indicar a qué llega: qué tienda, qué cuenta, en qué idioma y moneda llegan las respuestas.",
 "Prüfen, ob die Verbindung zum PrestaShop-Shop funktioniert, und melden, was sie erreicht: welcher Shop, welches Konto, in welcher Sprache und Währung die Antworten kommen."),
"Find orders in the PrestaShop shop and read one in full - search by reference, customer, date, status or amount, then open the order that matters.": (
 "Trouver des commandes dans la boutique PrestaShop et en lire une en entier : chercher par référence, client, date, statut ou montant, puis ouvrir la commande qui compte.",
 "Buscar pedidos en la tienda PrestaShop y leer uno completo: buscar por referencia, cliente, fecha, estado o importe, y abrir el pedido que importa.",
 "Bestellungen im PrestaShop-Shop finden und eine vollständig lesen: nach Referenz, Kunde, Datum, Status oder Betrag suchen und dann die relevante Bestellung öffnen."),
"Change the status of an order in the PrestaShop shop, one order at a time, with the checks that keep a customer from being emailed twice.": (
 "Changer le statut d'une commande dans la boutique PrestaShop, une commande à la fois, avec les vérifications qui évitent d'envoyer deux fois un e-mail au client.",
 "Cambiar el estado de un pedido en la tienda PrestaShop, un pedido cada vez, con las comprobaciones que evitan enviar dos veces un correo al cliente.",
 "Den Status einer Bestellung im PrestaShop-Shop ändern, eine Bestellung nach der anderen, mit den Prüfungen, die verhindern, dass ein Kunde zweimal eine E-Mail erhält."),
"Find, read and change products and categories in the PrestaShop shop, one at a time, without guessing an identifier.": (
 "Trouver, lire et modifier les produits et les catégories de la boutique PrestaShop, un à la fois, sans deviner d'identifiant.",
 "Buscar, leer y modificar los productos y las categorías de la tienda PrestaShop, uno cada vez, sin adivinar ningún identificador.",
 "Produkte und Kategorien im PrestaShop-Shop finden, lesen und ändern, eins nach dem anderen, ohne eine Kennung zu erraten."),
"See what runs low in the PrestaShop shop and set quantities, one product or combination at a time.": (
 "Voir ce qui manque dans la boutique PrestaShop et régler les quantités, un produit ou une déclinaison à la fois.",
 "Ver lo que escasea en la tienda PrestaShop y ajustar las cantidades, un producto o una combinación cada vez.",
 "Sehen, was im PrestaShop-Shop knapp wird, und Mengen festlegen, ein Produkt oder eine Kombination nach der anderen."),
"Order reference, number or customer name": (
 "Référence, numéro de commande ou nom du client",
 "Referencia, número de pedido o nombre del cliente",
 "Bestellreferenz, -nummer oder Kundenname"),
"Reference or number of the order": (
 "Référence ou numéro de la commande",
 "Referencia o número del pedido",
 "Referenz oder Nummer der Bestellung"),
"Status to set": (
 "Statut à appliquer",
 "Estado que aplicar",
 "Zu setzender Status"),
"Name, reference or EAN13 of a product": (
 "Nom, référence ou EAN13 d'un produit",
 "Nombre, referencia o EAN13 de un producto",
 "Name, Referenz oder EAN13 eines Produkts"),
"Quantity at or under which a product counts as low": (
 "Quantité à partir de laquelle un produit est considéré en stock bas",
 "Cantidad a partir de la cual un producto se considera con stock bajo",
 "Menge, ab der ein Produkt als knapp gilt"),
"Send a file to your assistant": (
 "Envoyer un fichier à votre assistant",
 "Enviar un archivo a su asistente",
 "Eine Datei an Ihren Assistenten senden"),
"Your assistant is asking for a file. It is sent straight to your shop - the assistant never sees it.": (
 "Votre assistant demande un fichier. Il part directement vers votre boutique - l'assistant ne le voit jamais.",
 "Su asistente pide un archivo. Se envía directamente a su tienda: el asistente nunca lo ve.",
 "Ihr Assistent fragt nach einer Datei. Sie geht direkt an Ihren Shop - der Assistent sieht sie nie."),
"What for": (
 "À quoi ça sert",
 "Para qué",
 "Wofür"),
"Asked by": (
 "Demandé par",
 "Solicitado por",
 "Angefordert von"),
"Accepted": (
 "Acceptés",
 "Aceptados",
 "Zulässig"),
"up to %count% MB": (
 "jusqu'à %count% Mo",
 "hasta %count% MB",
 "bis zu %count% MB"),
"One file, once. The request is dropped after fifteen minutes.": (
 "Un fichier, une fois. La demande est abandonnée au bout de quinze minutes.",
 "Un archivo, una vez. La solicitud se descarta al cabo de quince minutos.",
 "Eine Datei, einmal. Die Anfrage verfällt nach fünfzehn Minuten."),
"Send nothing": (
 "N'envoyer aucun fichier",
 "No enviar nada",
 "Nichts senden"),
"Send this file": (
 "Envoyer ce fichier",
 "Enviar este archivo",
 "Diese Datei senden"),
"File received": (
 "Fichier reçu",
 "Archivo recibido",
 "Datei empfangen"),
"The file has been received. Go back to your assistant and let it carry on.": (
 "Le fichier a bien été reçu. Retournez dans votre assistant et laissez-le continuer.",
 "El archivo se ha recibido. Vuelva a su asistente y deje que continúe.",
 "Die Datei wurde empfangen. Kehren Sie zu Ihrem Assistenten zurück und lassen Sie ihn weitermachen."),
"Nothing was sent": (
 "Rien n'a été envoyé",
 "No se ha enviado nada",
 "Es wurde nichts gesendet"),
"No file was sent. Go back to your assistant and tell it you would rather not.": (
 "Aucun fichier n'a été envoyé. Retournez dans votre assistant et dites-lui que vous préférez ne pas le faire.",
 "No se ha enviado ningún archivo. Vuelva a su asistente y dígale que prefiere no hacerlo.",
 "Es wurde keine Datei gesendet. Kehren Sie zu Ihrem Assistenten zurück und sagen Sie ihm, dass Sie es lieber nicht möchten."),
"Nothing to send": (
 "Rien à envoyer",
 "Nada que enviar",
 "Nichts zu senden"),
"This request has expired, has already been answered, or was not meant for your account. Ask your assistant again.": (
 "Cette demande a expiré, a déjà reçu une réponse, ou ne s'adressait pas à votre compte. Redemandez à votre assistant.",
 "Esta solicitud ha caducado, ya ha sido respondida o no iba dirigida a su cuenta. Pídaselo de nuevo a su asistente.",
 "Diese Anfrage ist abgelaufen, wurde bereits beantwortet oder war nicht für Ihr Konto bestimmt. Fragen Sie Ihren Assistenten erneut."),
"No file arrived. Choose one and send it again.": (
 "Aucun fichier n'est arrivé. Choisissez-en un et renvoyez-le.",
 "No ha llegado ningún archivo. Elija uno y envíelo de nuevo.",
 "Es ist keine Datei angekommen. Wählen Sie eine aus und senden Sie sie erneut."),
"That file is too large.": (
 "Ce fichier est trop volumineux.",
 "Ese archivo es demasiado grande.",
 "Diese Datei ist zu groß."),
"That file is not one of the accepted kinds.": (
 "Ce fichier ne fait pas partie des types acceptés.",
 "Ese archivo no es de los tipos aceptados.",
 "Diese Datei gehört nicht zu den zulässigen Arten."),
"The file could not be stored. Check that the shop can write inside its modules folder.": (
 "Le fichier n'a pas pu être enregistré. Vérifiez que la boutique peut écrire dans son dossier modules.",
 "El archivo no se ha podido guardar. Compruebe que la tienda puede escribir en su carpeta de módulos.",
 "Die Datei konnte nicht gespeichert werden. Prüfen Sie, ob der Shop in seinen Modulordner schreiben kann."),
"Your account is not attached to this shop, so it cannot connect an assistant to it. Ask an administrator to give you access to that shop.": (
 "Votre compte n'est pas rattaché à cette boutique, il ne peut donc pas y connecter d'assistant. Demandez à un administrateur de vous donner accès à cette boutique.",
 "Su cuenta no está asociada a esta tienda, así que no puede conectarle un asistente. Pida a un administrador que le dé acceso a esa tienda.",
 "Ihr Konto ist diesem Shop nicht zugeordnet und kann daher keinen Assistenten mit ihm verbinden. Bitten Sie einen Administrator, Ihnen Zugang zu diesem Shop zu geben."),

"Send files to your assistant": (
 "Envoyer des fichiers à votre assistant",
 "Enviar archivos a su asistente",
 "Dateien an Ihren Assistenten senden"),
"up to %count% files": (
 "jusqu'à %count% fichiers",
 "hasta %count% archivos",
 "bis zu %count% Dateien"),
"%size% each": ("%size% chacun", "%size% cada uno", "je %size%"),
"Already sent": ("Déjà envoyés", "Ya enviados", "Bereits gesendet"),
"Remove": ("Retirer", "Quitar", "Entfernen"),
"Add this file": ("Ajouter ce fichier", "Añadir este archivo", "Diese Datei hinzufügen"),
"You have sent everything that was asked for.": (
 "Vous avez envoyé tout ce qui était demandé.",
 "Ha enviado todo lo que se pedía.",
 "Sie haben alles gesendet, was angefordert wurde."),
"The request is dropped after fifteen minutes, and each file can only be collected once.": (
 "La demande est abandonnée au bout de quinze minutes, et chaque fichier ne peut être récupéré qu'une fois.",
 "La solicitud se descarta al cabo de quince minutos, y cada archivo solo puede recogerse una vez.",
 "Die Anfrage verfällt nach fünfzehn Minuten, und jede Datei kann nur einmal abgeholt werden."),
"You have already sent as many files as were asked for.": (
 "Vous avez déjà envoyé autant de fichiers qu'il en était demandé.",
 "Ya ha enviado tantos archivos como se pedían.",
 "Sie haben bereits so viele Dateien gesendet, wie angefordert wurden."),

"Drop your files here, or click to choose them": (
 "Déposez vos fichiers ici, ou cliquez pour les choisir",
 "Suelte sus archivos aquí, o haga clic para elegirlos",
 "Dateien hier ablegen, oder klicken zum Auswählen"),
"Drop your file here, or click to choose it": (
 "Déposez votre fichier ici, ou cliquez pour le choisir",
 "Suelte su archivo aquí, o haga clic para elegirlo",
 "Datei hier ablegen, oder klicken zum Auswählen"),
"%count% more can still be sent": (
 "encore %count% possibles",
 "aún se pueden enviar %count%",
 "noch %count% möglich"),
"Send": ("Envoyer", "Enviar", "Senden"),
"%count% files were not accepted.": (
 "%count% fichiers n'ont pas été acceptés.",
 "%count% archivos no se han aceptado.",
 "%count% Dateien wurden nicht angenommen."),

"Everything together was too large to send at once. Send fewer files at a time.": (
 "L'ensemble était trop volumineux pour partir d'un coup. Envoyez moins de fichiers à la fois.",
 "El conjunto era demasiado grande para enviarlo de una vez. Envíe menos archivos cada vez.",
 "Alles zusammen war zu groß für einen Versand. Senden Sie weniger Dateien auf einmal."),

"Configuration": (
 "Configuration",
 "Configuración",
 "Konfiguration"),
"Tools and prompts": (
 "Outils et prompts",
 "Herramientas y prompts",
 "Tools und Prompts"),
"Access and security": (
 "Accès et sécurité",
 "Acceso y seguridad",
 "Zugang und Sicherheit"),
"OAuth 2.1: every connection is approved by an employee, and an assistant never gets more rights than that employee.": (
 "OAuth 2.1 : chaque connexion est validée par un employé, et un assistant n'obtient jamais plus de droits que cet employé.",
 "OAuth 2.1: cada conexión la aprueba un empleado, y un asistente nunca obtiene más derechos que ese empleado.",
 "OAuth 2.1: Jede Verbindung wird von einem Mitarbeiter freigegeben, und ein Assistent erhält nie mehr Rechte als dieser Mitarbeiter."),
"Share your feedback": (
 "Donnez votre avis",
 "Danos tu opinión",
 "Ihr Feedback"),
"A bug, an idea, a tool you are missing? The module is built in the open on GitHub: tell us there.": (
 "Un bug, une idée, un outil qui vous manque ? Le module est développé en open source sur GitHub : dites-le-nous là-bas.",
 "¿Un error, una idea, una herramienta que echa en falta? El módulo se desarrolla en abierto en GitHub: cuéntenoslo allí.",
 "Ein Fehler, eine Idee, ein fehlendes Tool? Das Modul wird offen auf GitHub entwickelt: Sagen Sie es uns dort."),
"Give feedback": (
 "Donner mon avis",
 "Dar mi opinión",
 "Feedback geben"),
"Source code": (
 "Code source",
 "Código fuente",
 "Quellcode"),
"Open source under the AFL-3.0 licence": (
 "Open source sous licence AFL-3.0",
 "Código abierto bajo licencia AFL-3.0",
 "Open Source unter der Lizenz AFL-3.0"),
}
LANGS = [("fr-FR", 0), ("es-ES", 1), ("de-DE", 2)]
BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "translations")

def unit(src, target):
    return (
        "    <!-- %s -->\n"
        "    <trans-unit id=\"%s\" resname=\"%s\">\n"
        "      <source>%s</source>\n"
        "      <target>%s</target>\n"
        "    </trans-unit>\n"
    ) % (
        html.escape(src, quote=False),
        hashlib.md5(src.encode("utf-8")).hexdigest(),
        html.escape(src, quote=True),
        html.escape(src, quote=False),
        html.escape(target, quote=False),
    )

def write(locale, index):
    body = "".join(unit(src, values[index]) for src, values in T.items())
    xliff = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">\n'
        '  <file original="ModulesSomcpAdmin" source-language="en-US" target-language="%s" datatype="plaintext">\n'
        "    <body>\n%s    </body>\n"
        "  </file>\n"
        "</xliff>\n"
    ) % (locale, body)
    path = os.path.join(BASE, locale, "ModulesSomcpAdmin.%s.xlf" % locale)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(xliff)
    return path

def write_source():
    body = "".join(unit(src, src) for src in T)
    xliff = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">\n'
        '  <file original="ModulesSomcpAdmin" source-language="en-US" target-language="en-US" datatype="plaintext">\n'
        "    <body>\n%s    </body>\n"
        "  </file>\n"
        "</xliff>\n"
    ) % body
    path = os.path.join(BASE, "en-US", "ModulesSomcpAdmin.en-US.xlf")
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(xliff)
    return path

if __name__ == "__main__":
    written = [write_source()] + [write(locale, index) for locale, index in LANGS]
    print(json.dumps({"strings": len(T), "files": written}, indent=1))
