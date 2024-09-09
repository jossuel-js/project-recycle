<?php

function getUserAnnouncements($userId, $limit, $offset) {
    global $pdo;
    $stmt = $pdo->prepare('
        SELECT a.*, u.nome as usuario_nome, u.telefone as usuario_telefone 
        FROM recycle.tb_anuncios a 
        JOIN recycle.tb_users u ON a.user_id = u.id
        WHERE a.user_id = :user_id
        ORDER BY a.data_criacao DESC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as &$announcement) {
        if ($announcement['imagem']) {
            $imagemData = stream_get_contents($announcement['imagem']);
            $announcement['imagem'] = base64_encode($imagemData);
        }
    }

    return $announcements;
}
function findUserAnnouncements($userId, $pdo) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.nome as usuario_nome, u.telefone as usuario_telefone
        FROM recycle.tb_anuncios a
        JOIN recycle.tb_users u ON a.user_id = u.id
        WHERE a.user_id = :user_id
        ORDER BY a.data_criacao DESC
    ");
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as &$announcement) {
        if ($announcement['imagem']) {
            // Assumindo que 'imagem' é um campo BLOB que contém dados binários da imagem
            $imagemData = stream_get_contents($announcement['imagem']);
            $announcement['imagem'] = base64_encode($imagemData);
        }
    }

    return $announcements;
}

function countUserLikes($userId, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total_likes
            FROM recycle.profile_likes
            WHERE liked_user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_likes'] ?? 0;
    } catch (PDOException $e) {
        error_log('Error in countUserLikes: ' . $e->getMessage());
        return 0;
    }
}



function getTotalAnnouncementsCount() {     
    global $pdo;
    $stmt = $pdo->query('SELECT COUNT(*) FROM recycle.tb_anuncios');
    return $stmt->fetchColumn();
}

function likeAnnouncement($id) {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    $user_id = $_SESSION['user_id'];

    try {
        // Verifica se o usuário já curtiu o anúncio
        $stmt = $pdo->prepare('SELECT 1 FROM recycle.tb_announcement_likes WHERE announcement_id = :announcement_id AND user_id = :user_id');
        $stmt->bindParam(':announcement_id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->fetchColumn()) {
            // Já curtiu, então descurte
            $stmt = $pdo->prepare('DELETE FROM recycle.tb_announcement_likes WHERE announcement_id = :announcement_id AND user_id = :user_id');
            $stmt->bindParam(':announcement_id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $_SESSION['success'] = 'Descurtiu o anúncio com sucesso!';
        } else {
            // Não curtiu, então curta
            $stmt = $pdo->prepare('INSERT INTO recycle.tb_announcement_likes (announcement_id, user_id) VALUES (:announcement_id, :user_id)');
            $stmt->bindParam(':announcement_id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $_SESSION['success'] = 'Curtiu o anúncio com sucesso!';
        }

    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao processar o like: ' . $e->getMessage();
    }
}


function getAllAnnouncements($limit, $offset, $search_term = '') {
    global $pdo;

    // Inicializa a consulta SQL
    $query = "
        SELECT 
            a.id, a.assunto, a.descricao, a.imagem, a.user_id,a.preco, 
            u.telefone, u.nome
        FROM 
            recycle.tb_anuncios AS a 
        JOIN 
            recycle.tb_users AS u ON a.user_id = u.id 
        WHERE 
            a.ativo = true";

    // Adiciona a cláusula de busca, se houver um termo de busca
    if ($search_term) {
        $query .= " AND (a.assunto ILIKE :search_term OR a.descricao ILIKE :search_term)";
    }

    // Adiciona a cláusula de limite e deslocamento
    $query .= " ORDER BY a.data_criacao DESC LIMIT :limit OFFSET :offset";

    // Prepara a consulta
    $stmt = $pdo->prepare($query);

    // Vincula os parâmetros
    if ($search_term) {
        $search_term = '%' . $search_term . '%';
        $stmt->bindParam(':search_term', $search_term, PDO::PARAM_STR);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    // Obtém os anúncios
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Processa as imagens para base64
    foreach ($announcements as &$announcement) {
        if ($announcement['imagem']) {
            // Converte o recurso de fluxo em uma string e depois para base64
            $imagemData = stream_get_contents($announcement['imagem']);
            $announcement['imagem'] = base64_encode($imagemData);
        }
    }

    return $announcements;
}


function getAnnouncementDetails($announcementId, $userId) {
    global $pdo;
    
    // Consulta para obter a contagem de curtidas
    $stmt = $pdo->prepare('
        SELECT COUNT(*) as like_count
        FROM recycle.tb_announcement_likes
        WHERE announcement_id = :announcement_id
    ');
    $stmt->bindParam(':announcement_id', $announcementId, PDO::PARAM_INT);
    $stmt->execute();
    $likeCount = $stmt->fetchColumn();
    
    // Verifica se o usuário logado curtiu o anúncio
    $stmt = $pdo->prepare('
        SELECT COUNT(*) > 0
        FROM recycle.tb_announcement_likes
        WHERE announcement_id = :announcement_id AND user_id = :user_id
    ');
    $stmt->bindParam(':announcement_id', $announcementId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $userLiked = $stmt->fetchColumn();

    return [
        'like_count' => $likeCount,
        'user_liked' => $userLiked,
    ];
}
