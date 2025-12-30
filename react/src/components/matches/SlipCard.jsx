import React, { useState } from "react";
import {
  Card,
  CardContent,
  CardActions,
  Chip,
  Typography,
  Button,
  Box,
  Stack,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  IconButton,
  Tooltip,
  Divider,
  LinearProgress,
  Alert,
  Fade,
  Grow,
  Zoom,
  Collapse,
  alpha,
  Avatar,
} from "@mui/material";
import {
  Delete,
  Visibility,
  TrendingUp,
  AttachMoney,
  Warning,
  Info,
  Sports,
  ExpandMore,
  ExpandLess,
  Whatshot,
  EmojiEvents,
  Timeline,
  BarChart,
  CheckCircle,
  Cancel,
  LocalFireDepartment,
  Bolt,
  Star,
  Security,
} from "@mui/icons-material";
import { useTheme } from "@mui/material/styles";
import { styled } from "@mui/material/styles";

// Styled Components for Dark Theme
const GradientCard = styled(Card)(({ theme }) => ({
  background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.9)} 0%, ${alpha(
    theme.palette.background.default,
    0.95
  )} 100%)`,
  backdropFilter: "blur(10px)",
  border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
  transition: "all 0.4s cubic-bezier(0.4, 0, 0.2, 1)",
  position: "relative",
  overflow: "hidden",
  "&:hover": {
    transform: "translateY(-6px)",
    boxShadow: `0 20px 40px ${alpha(theme.palette.primary.main, 0.2)}`,
    borderColor: alpha(theme.palette.primary.main, 0.3),
    "&::before": {
      opacity: 0.6,
    },
  },
  "&::before": {
    content: '""',
    position: "absolute",
    top: 0,
    left: 0,
    right: 0,
    height: "3px",
    background: `linear-gradient(90deg, ${theme.palette.primary.main} 0%, ${theme.palette.secondary.main} 100%)`,
    opacity: 0,
    transition: "opacity 0.4s ease",
  },
}));

const RiskBadge = styled(Chip)(({ theme, risklevel }) => {
  const colors = {
    "High Risk": {
      bg: `linear-gradient(135deg, ${alpha(theme.palette.error.main, 0.2)} 0%, ${alpha(
        theme.palette.error.main,
        0.05
      )} 100%)`,
      text: theme.palette.error.light,
      border: alpha(theme.palette.error.main, 0.3),
      glow: alpha(theme.palette.error.main, 0.3),
    },
    "Medium Risk": {
      bg: `linear-gradient(135deg, ${alpha(theme.palette.warning.main, 0.2)} 0%, ${alpha(
        theme.palette.warning.main,
        0.05
      )} 100%)`,
      text: theme.palette.warning.light,
      border: alpha(theme.palette.warning.main, 0.3),
      glow: alpha(theme.palette.warning.main, 0.3),
    },
    "Low Risk": {
      bg: `linear-gradient(135deg, ${alpha(theme.palette.success.main, 0.2)} 0%, ${alpha(
        theme.palette.success.main,
        0.05
      )} 100%)`,
      text: theme.palette.success.light,
      border: alpha(theme.palette.success.main, 0.3),
      glow: alpha(theme.palette.success.main, 0.3),
    },
  };

  const color = colors[risklevel] || colors["Medium Risk"];

  return {
    background: color.bg,
    color: color.text,
    border: `1px solid ${color.border}`,
    fontWeight: 700,
    backdropFilter: "blur(5px)",
    boxShadow: `0 4px 15px ${color.glow}`,
    "& .MuiChip-icon": {
      color: color.text,
    },
  };
});

const ConfidenceProgress = styled(LinearProgress)(({ theme, value }) => ({
  height: 10,
  borderRadius: 5,
  backgroundColor: alpha(theme.palette.grey[800], 0.3),
  boxShadow: `inset 0 2px 4px ${alpha(theme.palette.common.black, 0.3)}`,
  "& .MuiLinearProgress-bar": {
    background: `linear-gradient(90deg, 
      ${value > 70 ? theme.palette.success.main : value > 50 ? theme.palette.warning.main : theme.palette.error.main} 0%,
      ${value > 70 ? theme.palette.success.light : value > 50 ? theme.palette.warning.light : theme.palette.error.light} 100%)`,
    borderRadius: 5,
    boxShadow: `0 0 10px ${alpha(
      value > 70
        ? theme.palette.success.main
        : value > 50
          ? theme.palette.warning.main
          : theme.palette.error.main,
      0.5
    )}`,
    animation: "pulse 2s infinite",
    "@keyframes pulse": {
      "0%, 100%": { opacity: 1 },
      "50%": { opacity: 0.8 },
    },
  },
}));

const SelectionCard = styled(Box)(({ theme, isfallback }) => {
  // Convert isfallback to boolean
  const isFallback = isfallback === "true";

  return {
    background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.8)} 0%, ${alpha(
      theme.palette.background.default,
      0.9
    )} 100%)`,
    backdropFilter: "blur(5px)",
    border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
    borderRadius: 2,
    padding: theme.spacing(1.5),
    transition: "all 0.3s ease",
    position: "relative",
    overflow: "hidden",
    "&:hover": {
      transform: "translateX(4px)",
      borderColor: isFallback
        ? alpha(theme.palette.warning.main, 0.5)
        : alpha(theme.palette.primary.main, 0.3),
      boxShadow: `0 8px 20px ${alpha(theme.palette.common.black, 0.2)}`,
    },
    "&::before": {
      content: '""',
      position: "absolute",
      left: 0,
      top: 0,
      bottom: 0,
      width: "4px",
      background: isFallback
        ? `linear-gradient(180deg, ${theme.palette.warning.main} 0%, ${theme.palette.warning.light} 100%)`
        : `linear-gradient(180deg, ${theme.palette.primary.main} 0%, ${theme.palette.secondary.main} 100%)`,
    },
  };
});

const ActionButton = styled(IconButton)(({ theme, colorvariant }) => ({
  background: `linear-gradient(135deg, ${alpha(
    colorvariant === "primary"
      ? theme.palette.primary.main
      : colorvariant === "error"
        ? theme.palette.error.main
        : theme.palette.info.main,
    0.1
  )} 0%, ${alpha(
    colorvariant === "primary"
      ? theme.palette.primary.dark
      : colorvariant === "error"
        ? theme.palette.error.dark
        : theme.palette.info.dark,
    0.05
  )} 100%)`,
  border: `1px solid ${alpha(
    colorvariant === "primary"
      ? theme.palette.primary.main
      : colorvariant === "error"
        ? theme.palette.error.main
        : theme.palette.info.main,
    0.2
  )}`,
  transition: "all 0.3s ease",
  "&:hover": {
    background: `linear-gradient(135deg, ${alpha(
      colorvariant === "primary"
        ? theme.palette.primary.main
        : colorvariant === "error"
          ? theme.palette.error.main
          : theme.palette.info.main,
      0.3
    )} 0%, ${alpha(
      colorvariant === "primary"
        ? theme.palette.primary.dark
        : colorvariant === "error"
          ? theme.palette.error.dark
          : theme.palette.info.dark,
      0.2
    )} 100%)`,
    transform: "scale(1.1)",
    boxShadow: `0 8px 20px ${alpha(
      colorvariant === "primary"
        ? theme.palette.primary.main
        : colorvariant === "error"
          ? theme.palette.error.main
          : theme.palette.info.main,
      0.3
    )}`,
  },
}));

const ReturnValue = styled(Typography)(({ theme, value }) => ({
  background: `linear-gradient(135deg, ${theme.palette.primary.main} 0%, ${theme.palette.secondary.main} 100%)`,
  WebkitBackgroundClip: "text",
  WebkitTextFillColor: "transparent",
  backgroundClip: "text",
  fontWeight: 800,
  textShadow: `0 0 20px ${alpha(theme.palette.primary.main, 0.3)}`,
  animation: "glow 2s infinite",
  "@keyframes glow": {
    "0%, 100%": { filter: "brightness(1)" },
    "50%": { filter: "brightness(1.3)" },
  },
}));

const SlipCard = ({ slip, onDelete, onViewDetail, index }) => {
  const theme = useTheme();
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [expanded, setExpanded] = useState(false);

  const getConfidenceIcon = (percentage) => {
    if (percentage > 80)
      return <Star sx={{ color: theme.palette.success.light }} />;
    if (percentage > 60)
      return <TrendingUp sx={{ color: theme.palette.warning.light }} />;
    if (percentage > 40)
      return <Timeline sx={{ color: theme.palette.info.light }} />;
    return <Warning sx={{ color: theme.palette.error.light }} />;
  };

  const getOddsColor = (odds) => {
    if (odds > 10) return theme.palette.error.light;
    if (odds > 5) return theme.palette.warning.light;
    return theme.palette.success.light;
  };

  const handleDelete = () => {
    setDeleteConfirmOpen(false);
    onDelete(slip.slip_id);
  };

  const confidencePercentage = slip.confidence_score * 100;

  return (
    <>
      <Grow in={true} timeout={500 + (index || 0) * 100}>
        <GradientCard elevation={0}>
          <CardContent sx={{ p: 3 }}>
            {/* Header with ID and Confidence */}
            <Box
              display="flex"
              justifyContent="space-between"
              alignItems="flex-start"
              mb={3}
            >
              <Box>
                <Typography
                  variant="h6"
                  component="div"
                  gutterBottom
                  sx={{
                    display: "flex",
                    alignItems: "center",
                    gap: 1,
                    fontWeight: 700,
                  }}
                >
                  <Bolt
                    sx={{ color: theme.palette.primary.light, fontSize: 20 }}
                  />
                  {slip.slip_id}
                  {slip.variation_type === "core" && (
                    <Chip
                      icon={<Whatshot />}
                      label="Core"
                      size="small"
                      sx={{
                        background: alpha(theme.palette.primary.main, 0.15),
                        color: theme.palette.primary.light,
                        fontWeight: 600,
                        ml: 1,
                      }}
                    />
                  )}
                </Typography>

                <Stack
                  direction="row"
                  spacing={1}
                  flexWrap="wrap"
                  sx={{ mt: 1 }}
                >
                  <Chip
                    icon={getConfidenceIcon(confidencePercentage)}
                    label={`${confidencePercentage.toFixed(1)}% Confidence`}
                    size="small"
                    sx={{
                      background: alpha(theme.palette.primary.main, 0.1),
                      color: theme.palette.primary.light,
                      fontWeight: 600,
                      border: `1px solid ${alpha(theme.palette.primary.main, 0.3)}`,
                    }}
                  />
                  <RiskBadge
                    icon={<Security />}
                    label={slip.risk_level}
                    risklevel={slip.risk_level}
                    size="small"
                  />
                  <Chip
                    icon={<EmojiEvents />}
                    label={`${slip.legs.length} Legs`}
                    size="small"
                    sx={{
                      background: alpha(theme.palette.info.main, 0.1),
                      color: theme.palette.info.light,
                      fontWeight: 600,
                      border: `1px solid ${alpha(theme.palette.info.main, 0.3)}`,
                    }}
                  />
                </Stack>
              </Box>

              <Box textAlign="right">
                <Typography
                  variant="caption"
                  color="text.secondary"
                  sx={{ opacity: 0.7 }}
                >
                  Potential Return
                </Typography>
                <ReturnValue variant="h4">
                  €{(slip.calculated_return || slip.possible_return).toFixed(2)}
                </ReturnValue>
                <Typography
                  variant="body2"
                  color="text.secondary"
                  sx={{ mt: 0.5 }}
                >
                  Total Odds:{" "}
                  <Box
                    component="span"
                    sx={{
                      color: getOddsColor(slip.total_odds),
                      fontWeight: 700,
                    }}
                  >
                    {slip.total_odds.toFixed(2)}x
                  </Box>
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Stake: €{(slip.calculated_stake || slip.stake).toFixed(2)}
                </Typography>
              </Box>
            </Box>

            {/* Confidence Progress Bar */}
            <Box mb={3}>
              <Box
                display="flex"
                justifyContent="space-between"
                alignItems="center"
                mb={1}
              >
                <Typography variant="subtitle2" sx={{ opacity: 0.9 }}>
                  Confidence Level
                </Typography>
                <Typography
                  variant="body2"
                  sx={{
                    fontWeight: 700,
                    color:
                      confidencePercentage > 70
                        ? theme.palette.success.light
                        : confidencePercentage > 50
                          ? theme.palette.warning.light
                          : theme.palette.error.light,
                  }}
                >
                  {confidencePercentage.toFixed(1)}%
                </Typography>
              </Box>
              <ConfidenceProgress
                variant="determinate"
                value={confidencePercentage}
              />
              <Box display="flex" justifyContent="space-between" mt={0.5}>
                <Typography
                  variant="caption"
                  color="text.secondary"
                  sx={{ opacity: 0.7 }}
                >
                  Low
                </Typography>
                <Typography
                  variant="caption"
                  color="text.secondary"
                  sx={{ opacity: 0.7 }}
                >
                  Medium
                </Typography>
                <Typography
                  variant="caption"
                  color="text.secondary"
                  sx={{ opacity: 0.7 }}
                >
                  High
                </Typography>
              </Box>
            </Box>

            {/* Selections Section */}
            <Box>
              <Typography
                variant="subtitle2"
                gutterBottom
                sx={{
                  display: "flex",
                  alignItems: "center",
                  gap: 1,
                  mb: 2,
                  opacity: 0.9,
                }}
              >
                <Sports sx={{ color: theme.palette.primary.light }} />
                Selections ({slip.legs.length})
              </Typography>

              <Collapse in={expanded}>
                <Stack spacing={1.5}>
                  {slip.legs.map((leg, idx) => (
                    <Zoom in={true} timeout={300 + idx * 50} key={idx}>
                      {/* Pass isfallback as string instead of boolean */}
                      <SelectionCard isfallback={leg.is_fallback.toString()}>
                        <Box
                          display="flex"
                          justifyContent="space-between"
                          alignItems="center"
                        >
                          <Box>
                            <Typography
                              variant="body2"
                              fontWeight={600}
                              sx={{ opacity: 0.95 }}
                            >
                              {leg.match_id}
                            </Typography>
                            <Typography
                              variant="caption"
                              color="text.secondary"
                              sx={{ opacity: 0.8 }}
                            >
                              {leg.market} • {leg.selection.replace(/_/g, " ")}
                              {leg.is_fallback && (
                                <Chip
                                  label="Fallback"
                                  size="small"
                                  sx={{
                                    ml: 1,
                                    background: alpha(
                                      theme.palette.warning.main,
                                      0.15
                                    ),
                                    color: theme.palette.warning.light,
                                    fontSize: "0.65rem",
                                    height: 18,
                                  }}
                                />
                              )}
                            </Typography>
                          </Box>
                          <Box display="flex" alignItems="center" gap={1}>
                            <Typography
                              variant="h6"
                              sx={{
                                color: getOddsColor(leg.odds),
                                fontWeight: 800,
                                textShadow: `0 0 10px ${alpha(getOddsColor(leg.odds), 0.3)}`,
                              }}
                            >
                              {leg.odds.toFixed(2)}
                            </Typography>
                            {leg.odds > 5 && (
                              <LocalFireDepartment
                                sx={{
                                  color: theme.palette.error.light,
                                  fontSize: 18,
                                }}
                              />
                            )}
                          </Box>
                        </Box>
                      </SelectionCard>
                    </Zoom>
                  ))}
                </Stack>
              </Collapse>

              {!expanded && (
                <Typography
                  variant="body2"
                  color="text.secondary"
                  sx={{
                    p: 2,
                    background: alpha(theme.palette.background.paper, 0.5),
                    borderRadius: 2,
                    border: `1px dashed ${alpha(theme.palette.divider, 0.3)}`,
                    opacity: 0.8,
                  }}
                >
                  {slip.legs
                    .map(
                      (leg) =>
                        `${leg.selection.replace(/_/g, " ")} (${leg.odds.toFixed(2)}x)`
                    )
                    .join(" • ")}
                </Typography>
              )}

              <Box textAlign="center" mt={2}>
                <Button
                  size="small"
                  endIcon={expanded ? <ExpandLess /> : <ExpandMore />}
                  onClick={() => setExpanded(!expanded)}
                  sx={{
                    background: alpha(theme.palette.primary.main, 0.1),
                    color: theme.palette.primary.light,
                    border: `1px solid ${alpha(theme.palette.primary.main, 0.3)}`,
                    borderRadius: 2,
                    px: 2,
                    "&:hover": {
                      background: alpha(theme.palette.primary.main, 0.2),
                    },
                  }}
                >
                  {expanded ? "Show Less" : "Show All Selections"}
                </Button>
              </Box>
            </Box>
          </CardContent>

          <Divider sx={{ borderColor: alpha(theme.palette.divider, 0.1) }} />

          {/* Actions */}
          <CardActions
            sx={{
              justifyContent: "space-between",
              p: 2,
              background: alpha(theme.palette.background.paper, 0.5),
            }}
          >
            <Box display="flex" gap={1}>
              <Tooltip title="View Detailed Analysis" arrow>
                <span>
                  <ActionButton
                    colorvariant="primary"
                    onClick={() => onViewDetail(slip.slip_id)}
                    size="small"
                  >
                    <Visibility sx={{ color: theme.palette.primary.light }} />
                  </ActionButton>
                </span>
              </Tooltip>
              <Tooltip title="More Information" arrow>
                <span>
                  <ActionButton
                    colorvariant="info"
                    onClick={() => {
                      /* Add detailed analysis modal */
                    }}
                    size="small"
                  >
                    <Info sx={{ color: theme.palette.info.light }} />
                  </ActionButton>
                </span>
              </Tooltip>
            </Box>

            <Box display="flex" alignItems="center" gap={1}>
              {slip.metrics?.roi_percentage > 1000 && (
                <Chip
                  icon={<Whatshot />}
                  label={`ROI: ${slip.metrics.roi_percentage.toFixed(0)}%`}
                  size="small"
                  sx={{
                    background: alpha(theme.palette.success.main, 0.15),
                    color: theme.palette.success.light,
                    fontWeight: 600,
                    border: `1px solid ${alpha(theme.palette.success.main, 0.3)}`,
                  }}
                />
              )}
              <Tooltip title="Delete Slip" arrow>
                <span>
                  <ActionButton
                    colorvariant="error"
                    onClick={() => setDeleteConfirmOpen(true)}
                    size="small"
                  >
                    <Delete sx={{ color: theme.palette.error.light }} />
                  </ActionButton>
                </span>
              </Tooltip>
            </Box>
          </CardActions>
        </GradientCard>
      </Grow>

      {/* Delete Confirmation Dialog */}
      <Dialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        PaperProps={{
          sx: {
            background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.95)} 0%, ${alpha(
              theme.palette.background.default,
              0.98
            )} 100%)`,
            backdropFilter: "blur(20px)",
            border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
            borderRadius: 4,
          },
        }}
      >
        <DialogTitle sx={{ fontWeight: 700, pb: 1 }}>Delete Slip</DialogTitle>
        <DialogContent>
          <Alert
            severity="warning"
            sx={{
              mb: 3,
              background: alpha(theme.palette.warning.main, 0.1),
              border: `1px solid ${alpha(theme.palette.warning.main, 0.2)}`,
              color: theme.palette.warning.light,
              borderRadius: 3,
            }}
            icon={<Warning />}
          >
            Are you sure you want to delete slip <strong>{slip.slip_id}</strong>
            ?
          </Alert>

          <Box
            sx={{
              p: 2,
              background: alpha(theme.palette.background.paper, 0.5),
              borderRadius: 2,
              mb: 2,
            }}
          >
            <Typography variant="body2" color="text.secondary" gutterBottom>
              This slip contains:
            </Typography>
            <Stack spacing={0.5}>
              <Box display="flex" alignItems="center" gap={1}>
                <CheckCircle
                  sx={{ fontSize: 16, color: theme.palette.success.light }}
                />
                <Typography variant="caption" color="text.secondary">
                  {slip.legs.length} betting selections
                </Typography>
              </Box>
              <Box display="flex" alignItems="center" gap={1}>
                <AttachMoney
                  sx={{ fontSize: 16, color: theme.palette.warning.light }}
                />
                <Typography variant="caption" color="text.secondary">
                  €{(slip.calculated_return || slip.possible_return).toFixed(2)}{" "}
                  potential return
                </Typography>
              </Box>
              <Box display="flex" alignItems="center" gap={1}>
                <BarChart
                  sx={{ fontSize: 16, color: theme.palette.info.light }}
                />
                <Typography variant="caption" color="text.secondary">
                  {confidencePercentage.toFixed(1)}% confidence score
                </Typography>
              </Box>
            </Stack>
          </Box>

          <Typography
            variant="body2"
            color="text.secondary"
            sx={{ opacity: 0.8 }}
          >
            This action cannot be undone. The slip and all its analysis data
            will be permanently removed.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ p: 3, pt: 0 }}>
          <Button
            onClick={() => setDeleteConfirmOpen(false)}
            sx={{
              borderRadius: 3,
              background: alpha(theme.palette.grey[700], 0.2),
              color: theme.palette.grey[300],
              "&:hover": {
                background: alpha(theme.palette.grey[700], 0.4),
              },
            }}
          >
            Cancel
          </Button>
          <Button
            onClick={handleDelete}
            startIcon={<Delete />}
            sx={{
              borderRadius: 3,
              background: `linear-gradient(135deg, ${theme.palette.error.main} 0%, ${theme.palette.error.dark} 100%)`,
              color: theme.palette.common.white,
              fontWeight: 600,
              "&:hover": {
                boxShadow: `0 8px 20px ${alpha(theme.palette.error.main, 0.3)}`,
                transform: "translateY(-2px)",
              },
            }}
          >
            Delete Permanently
          </Button>
        </DialogActions>
      </Dialog>
    </>
  );
};

export default SlipCard;
