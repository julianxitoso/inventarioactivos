document.addEventListener("DOMContentLoaded", function() {

    // 1. URL y configuración
    const chatbotUrl = "https://aiarpesodfront.onrender.com/View/ChatbotInventarioTI/index.html";

    // 2. Crear los estilos CSS dinámicamente
    const styles = `
        #chatbot-container { 
            display: none; 
        }
        body.chatbot-is-open #chatbot-container { 
            display: block; /* Solo necesitamos que sea visible */
        }
    `;
    const styleSheet = document.createElement("style");
    styleSheet.innerText = styles;
    document.head.appendChild(styleSheet);


    // 3. Crear los elementos del chatbot
    // 3.1. Botón Flotante Principal
    const chatButton = document.createElement('div');
    chatButton.id = 'chatbot-button';
    chatButton.innerHTML = `<i class="bi bi-chat-dots-fill" style="font-size: 28px; line-height: 1;"></i>`;
    chatButton.style.position = 'fixed';
    chatButton.style.bottom = '20px';
    chatButton.style.right = '20px';
    chatButton.style.width = '60px';
    chatButton.style.height = '60px';
    chatButton.style.borderRadius = '50%';
    chatButton.style.backgroundColor = '#191970';
    chatButton.style.color = 'white';
    chatButton.style.display = 'flex';
    chatButton.style.justifyContent = 'center';
    chatButton.style.alignItems = 'center';
    chatButton.style.cursor = 'pointer';
    chatButton.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
    chatButton.style.zIndex = '9998';

    // 3.2. Contenedor Principal del Chat
    const chatbotContainer = document.createElement('div');
    chatbotContainer.id = 'chatbot-container';
    chatbotContainer.style.position = 'fixed';
    chatbotContainer.style.bottom = '90px';
    chatbotContainer.style.right = '20px';
    chatbotContainer.style.width = '380px';
    chatbotContainer.style.height = '600px';
    chatbotContainer.style.boxShadow = '0 5px 20px rgba(0,0,0,0.25)';
    chatbotContainer.style.borderRadius = '15px';
    chatbotContainer.style.zIndex = '9999';

    // 3.3. Botón de Cerrar Flotante
    const closeButton = document.createElement('button');
    closeButton.id = 'chatbot-close-button';
    closeButton.innerHTML = '&times;'; // Símbolo 'X'
    closeButton.style.position = 'absolute';
    closeButton.style.top = '5px';
    closeButton.style.right = '5px';
    closeButton.style.width = '28px';
    closeButton.style.height = '28px';
    closeButton.style.background = 'rgba(0, 0, 0, 0.4)';
    closeButton.style.color = 'white';
    closeButton.style.border = 'none';
    closeButton.style.borderRadius = '50%';
    closeButton.style.fontSize = '22px';
    closeButton.style.lineHeight = '28px';
    closeButton.style.textAlign = 'center';
    closeButton.style.cursor = 'pointer';
    closeButton.style.zIndex = '10000'; // Para que esté sobre el iframe
    closeButton.style.padding = '0';

    // 3.4. Iframe para el contenido del chat
    const chatbotIframe = document.createElement('iframe');
    chatbotIframe.id = 'chatbot-iframe';
    chatbotIframe.src = '';
    chatbotIframe.style.border = 'none';
    chatbotIframe.style.width = '100%';
    chatbotIframe.style.height = '100%';
    chatbotIframe.style.borderRadius = '15px';

    // 3.5. Ensamblar las partes
    chatbotContainer.appendChild(chatbotIframe);
    chatbotContainer.appendChild(closeButton);

    document.body.appendChild(chatButton);
    document.body.appendChild(chatbotContainer);


    // 4. Lógica de apertura/cierre y estado (sin cambios)
    let isChatbotLoaded = false;

    function openChat() {
        if (!isChatbotLoaded) {
            chatbotIframe.src = chatbotUrl;
            isChatbotLoaded = true;
        }
        document.body.classList.add('chatbot-is-open');
        sessionStorage.setItem('chatbotOpen', 'true');
    }

    function closeChat() {
        document.body.classList.remove('chatbot-is-open');
        sessionStorage.setItem('chatbotOpen', 'false');
    }

    function toggleChat() {
        const isChatOpen = document.body.classList.contains('chatbot-is-open');
        if (isChatOpen) {
            closeChat();
        } else {
            openChat();
        }
    }
    
    // 5. Comprobar el estado al cargar la página (sin cambios)
    try {
        if (sessionStorage.getItem('chatbotOpen') === 'true') {
            openChat();
        }
    } catch (e) {
        console.error("No se pudo acceder a sessionStorage.", e);
    }
    
    // 6. Asignar los eventos a los botones (sin cambios)
    chatButton.addEventListener('click', toggleChat);
    closeButton.addEventListener('click', closeChat);
});