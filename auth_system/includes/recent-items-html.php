                    <!-- Recently Used Items -->
                    <?php if (count($recent_items) > 0): ?>
                    <div class="d-card">
                        <div class="d-card-header">
                            <div class="d-card-title">Recently used</div>
                        </div>
                        <div class="d-qa-list">
                            <?php foreach ($recent_items as $ri => $item):
                                $icon = getServiceIcon($item['entry_title']);
                                $colors = ['linear-gradient(135deg,#6366f1,#8b5cf6)', 'linear-gradient(135deg,#f59e0b,#ec4899)', 'linear-gradient(135deg,#22c55e,#16a34a)'];
                                $color = $colors[$ri % count($colors)];
                                $title = htmlspecialchars($item['entry_title'] ?? 'Untitled');
                                $type = htmlspecialchars($item['entry_type']);
                                $time = date('M j', strtotime($item['accessed_at']));
                            ?>
                            <div class="d-qa-item" style="opacity:0.85;">
                                <div class="d-qa-icon" style="background:<?php echo $color; ?>; font-size:1.3rem;"><?php echo $icon; ?></div>
                                <div class="d-qa-info">
                                    <div class="d-qa-name"><?php echo $title; ?></div>
                                    <div class="d-qa-user"><?php echo ucfirst($type); ?> • <?php echo $time; ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
